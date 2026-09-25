/**
 * Retnly storefront pixel for Magento.
 *
 * WHY THIS EXISTS
 *   The backend has had a complete Magento pixel receiver for months --
 *   `api/v4/magento/views.py::MagentoPixelEventView`, plus the MagentoPixelEvent
 *   and MagentoUniqueCustomer tables behind it. Nothing ever POSTed to it, so
 *   those tables stayed empty, no Magento shopper was ever identified from the
 *   storefront, and no push token was ever registered.
 *
 * WHAT IT SENDS
 *   Only the five names the receiver's TRACKED_EVENTS allowlist accepts. Any
 *   other name is discarded server-side with a 200, so emitting one would be an
 *   invisible no-op rather than an error -- worth stating, because that is
 *   exactly the failure mode that hides a typo.
 *
 * IDENTITY
 *   `pixelClientId` is a per-browser id kept in localStorage. It is what links a
 *   device's push token to a person once a later event carries their email or
 *   phone. It is NOT a person: a cookie clear mints a new one, which is why the
 *   receiver re-links MagentoUniqueCustomer rows when a duplicate
 *   MerchantCustomer is collapsed.
 *
 * DELIVERY
 *   `fetch` with `keepalive`, which survives the page being torn down — the two
 *   events most worth having (checkout_started, checkout_completed) fire during
 *   unload.
 *
 *   NOT `sendBeacon`. It looks made for this and silently lost every event: a
 *   Blob of type application/json is not CORS-safelisted, so the beacon needed a
 *   preflight, passed it, then failed the cross-origin check itself — while
 *   returning TRUE, because the return value means "queued", not "delivered".
 *   See the note in send() before reintroducing it.
 */
define([
    'jquery',
    'Magento_Customer/js/customer-data'
], function ($, customerData) {
    'use strict';

    var CLIENT_ID_KEY = 'retnly_pixel_client_id';
    var SENT_IDS_KEY = 'retnly_pixel_sent_ids';
    //: Set when the shopper dismisses the push opt-in bar, so it is asked once
    //: and not on every page. Cleared only by clearing site data.
    var PUSH_DECLINED_KEY = 'retnly_push_declined';
    //: Single-flight guard for the Firebase SDK load — see loadFirebase().
    var firebaseLoading = false;
    var firebaseWaiting = [];
    //: registerPush() legitimately runs more than once (on load, and again when
    //: the opt-in bar is accepted). onMessage must be bound exactly once, or one
    //: push renders as two notifications.
    var foregroundBound = false;

    // Mirrors MagentoPixelEventView.TRACKED_EVENTS exactly. Kept as an explicit
    // list so a name this file invents fails loudly here instead of being
    // silently dropped by the receiver.
    var TRACKED = [
        'product_viewed',
        'product_added_to_cart',
        'product_removed_from_cart',
        'cart_viewed',
        'checkout_started',
        'checkout_completed'
    ];

    var config = {};
    var pushToken = null;

    function uuid() {
        if (window.crypto && window.crypto.randomUUID) {
            return window.crypto.randomUUID();
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }

    function storage() {
        // Safari in private mode throws on localStorage access rather than
        // returning null, and an uncaught throw here would take the whole
        // storefront script down with it.
        try {
            return window.localStorage;
        } catch (e) {
            return null;
        }
    }

    function clientId() {
        var store = storage();
        if (!store) {
            return uuid();
        }
        var id = store.getItem(CLIENT_ID_KEY);
        if (!id) {
            id = uuid();
            store.setItem(CLIENT_ID_KEY, id);
        }
        return id;
    }

    /**
     * Per-tab de-dupe for events that a page can legitimately re-render.
     *
     * `cart_viewed` fires off a customer-data section update, and Magento
     * refreshes that section on navigations that did not actually re-view the
     * cart. The receiver de-dupes on `event.id` too, but only after a network
     * round trip -- doing it here keeps the noise off the wire entirely.
     */
    function alreadySent(key) {
        var store = storage();
        if (!store) {
            return false;
        }
        var seen;
        try {
            seen = JSON.parse(store.getItem(SENT_IDS_KEY) || '[]');
        } catch (e) {
            seen = [];
        }
        if (seen.indexOf(key) !== -1) {
            return true;
        }
        seen.push(key);
        // Bounded: this is a browsing session's worth of keys, not a log.
        store.setItem(SENT_IDS_KEY, JSON.stringify(seen.slice(-50)));
        return false;
    }

    function send(eventName, payload, dedupeKey) {
        if (TRACKED.indexOf(eventName) === -1) {
            return;
        }
        if (dedupeKey && alreadySent(dedupeKey)) {
            return;
        }

        var body = {
            storeDomain: config.storeDomain,
            meta: { appKey: config.appKey },
            visitor: {
                pixelClientId: clientId(),
                pushToken: pushToken || ''
            },
            event: {
                name: eventName,
                id: uuid(),
                payload: payload || {},
                occurredAt: new Date().toISOString()
            }
        };

        var json = JSON.stringify(body);

        // fetch + keepalive, NOT navigator.sendBeacon.
        //
        // The beacon was tried first because checkout_started and
        // checkout_completed fire while the page is unloading. It silently lost
        // EVERY event, on every store:
        //
        //   * sendBeacon sends a Blob of type application/json, which is not a
        //     CORS-safelisted content type, so the beacon needs a preflight. The
        //     preflight passed (200); the beacon itself then failed the
        //     cross-origin check and was dropped with no response at all.
        //   * sendBeacon returns TRUE regardless — it reports that the request
        //     was queued, not that it succeeded. So the old code took the
        //     `return` and the fetch below, which works, was never reached.
        //   * The pixel swallows errors by design, so nothing was ever logged.
        //     The symptom was zero rows in MagentoPixelEvent with a correctly
        //     configured store and a healthy endpoint.
        //
        // `keepalive` is what the beacon was there for: it is specified to let a
        // request outlive the page, which is exactly the unload case. Unlike the
        // beacon it is a real CORS request, so the preflight applies to it and
        // the response is actually checked.
        try {
            window.fetch(config.endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: json,
                keepalive: true,
                mode: 'cors',
                credentials: 'omit'
            })['catch'](function () {
                // A pixel must never surface a network error to the shopper.
            });
        } catch (e) {
            // Same reasoning.
        }
    }

    /**
     * Whatever the storefront currently knows about who this is.
     *
     * A guest checkout only reveals an email at the checkout step, which is why
     * identity is attached to EVERY event rather than captured once: the
     * receiver upserts the MerchantCustomer from the first event that carries
     * one, and re-points this browser's MagentoUniqueCustomer row at them.
     */
    function identity() {
        var customer = customerData.get('customer')();
        var out = {};

        if (customer && customer.email) {
            out.email = customer.email;
        }
        if (customer && customer.firstname) {
            out.customer = {
                firstName: customer.firstname,
                lastName: customer.lastname || ''
            };
        }
        guestIdentity(out);
        return out;
    }

    /**
     * Fill in a GUEST's details from Magento's own checkout cache.
     *
     * WHY THIS IS NEEDED
     *   `customerData.get('customer')` is populated only for a shopper who is
     *   LOGGED IN. The note above this used to say a guest's email arrives at the
     *   checkout step — it never did, because that section is precisely the one a
     *   guest never has. The cost was invisible: a guest's browser registered a
     *   push token onto a MagentoUniqueCustomer row whose merchant_customer stayed
     *   NULL, and every automation that resolves recipients through the customer
     *   (workflow_engine._resolve_push_targets, which filters
     *   `merchant_customer=mc`) then found no device and skipped with
     *   "no_push_token" while the run reported success.
     *
     *   `checkout-data` is Magento's OWN client-side checkout cache, written by
     *   Magento_Checkout/js/checkout-data through the very customer-data store
     *   this file already depends on. No new dependency, and nothing that has to
     *   be kept in step with the checkout UI.
     *
     * validatedEmailValue ONLY
     *   `inputFieldEmailValue` holds whatever sits in the box right now, including
     *   a half-typed address. The receiver get_or_creates a MerchantCustomer from
     *   any email it is handed, so sending that would seed the merchant's CRM with
     *   rows like `j@` and `jo@gm`. The validated value is written once the field
     *   has passed Magento's own check.
     *
     * NO PHONE, deliberately
     *   `telephone` is in this same cache and is tempting, because MerchantCustomer
     *   is keyed on phone before email. But it is persisted on field change, so a
     *   partially typed number would be normalised and keyed into a junk customer
     *   that nothing later merges away. Email is sufficient to link the device:
     *   the order ingest resolves the same shopper by email too, so both sides
     *   land on one MerchantCustomer.
     */
    function guestIdentity(out) {
        // A logged-in shopper is already authoritative; never let a stale
        // checkout cache from an earlier guest session overwrite them.
        if (out.email) {
            return;
        }
        var data;
        try {
            data = customerData.get('checkout-data')();
        } catch (e) {
            return;
        }
        if (!data) {
            return;
        }
        if (data.validatedEmailValue) {
            out.email = data.validatedEmailValue;
        }
        var address = data.shippingAddressFromData || data.billingAddressFromData;
        if (!out.customer && address && address.firstname) {
            out.customer = {
                firstName: address.firstname,
                lastName: address.lastname || ''
            };
        }
    }

    function cartPayload(cart) {
        var items = (cart && cart.items) || [];
        return {
            cartTotal: cart && cart.subtotalAmount ? cart.subtotalAmount : null,
            itemsCount: (cart && cart.summary_count) || 0,
            items: items.map(function (item) {
                return {
                    sku: item.product_sku,
                    name: item.product_name,
                    qty: item.qty,
                    price: item.product_price_value,
                    productId: item.product_id
                };
            })
        };
    }

    /**
     * Firebase web SDK, loaded only when push registration is configured.
     *
     * Deliberately lazy and deliberately failure-tolerant: a merchant who has
     * not filled in the Firebase fields gets event tracking with no SDK
     * download at all, and a merchant whose credentials are wrong gets a
     * console warning rather than a broken storefront.
     */
    /**
     * Load the Firebase compat SDK with plain <script> tags, NOT through RequireJS.
     *
     * This looked like a job for `require([url, url], cb)` and that is exactly what
     * it used to do. It never worked once:
     *
     *   Firebase's compat bundles are UMD. They detect an AMD loader and call
     *   define() to register NAMED modules — '@firebase/app-compat' and
     *   '@firebase/app'. RequireJS then resolves those names against the theme's
     *   static root, requests
     *   /static/version.../Magento/luma/en_US/@firebase/app-compat.js, gets
     *   Magento's 404 HTML page, refuses to execute it as script, and fails the
     *   whole chain with "Script error for @firebase/app-compat". getToken() was
     *   never reached, so no device could ever register.
     *
     * Hiding define() for the duration makes the same bundles take their plain
     * browser path and attach window.firebase, which is what the code below wants.
     * It is restored as soon as the last script settles — including on error, or
     * RequireJS would stay broken for the rest of the page.
     *
     * `async = false` keeps the two in order: messaging-compat needs app-compat
     * to have run first.
     */
    function loadFirebase(done) {
        if (window.firebase && window.firebase.messaging) {
            done();
            return;
        }

        // One load at a time. registerPush() can legitimately run twice on a
        // page — once at init when permission is already granted, once from the
        // opt-in bar's Allow — and two overlapping loads would each save and
        // restore `define.amd`, with the second saving the CLEARED value and
        // restoring undefined. Every UMD library loaded afterwards would then
        // take its non-AMD branch for the rest of the page.
        if (firebaseLoading) {
            firebaseWaiting.push(done);
            return;
        }
        firebaseLoading = true;
        firebaseWaiting.push(done);

        function finish() {
            firebaseLoading = false;
            var waiting = firebaseWaiting.splice(0, firebaseWaiting.length);
            for (var w = 0; w < waiting.length; w++) {
                waiting[w]();
            }
        }

        var urls = [
            'https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js',
            'https://www.gstatic.com/firebasejs/10.12.2/firebase-messaging-compat.js'
        ];
        // Hide the AMD *marker*, never define() itself.
        //
        // Blanking window.define was tried and broke the storefront: Magento
        // loads scripts continuously, and anything calling define() during the
        // few hundred milliseconds the SDK was fetching died with
        // "define is not a function" — taking the rest of the page's JS with it,
        // including this pixel's own opt-in bar.
        //
        // A UMD bundle branches on `typeof define === 'function' && define.amd`.
        // Clearing only `.amd` sends Firebase down its plain-browser path while
        // leaving define() fully callable for everyone else. RequireJS does not
        // consult its own `.amd` marker to work, so nothing else notices.
        var amdMarker;
        var hadMarker = false;
        var restored = false;

        function restore() {
            if (restored) {
                return;
            }
            restored = true;
            if (hadMarker && typeof window.define === 'function') {
                window.define.amd = amdMarker;
            }
        }

        if (typeof window.define === 'function' && window.define.amd) {
            hadMarker = true;
            amdMarker = window.define.amd;
            try {
                window.define.amd = undefined;
            } catch (e) {
                // Frozen loader: the SDK would register as a named AMD module and
                // 404. Skip rather than break the page or spam failed requests.
                firebaseLoading = false;
                firebaseWaiting.length = 0;
                return;
            }
        }

        var index = 0;
        (function next() {
            if (index >= urls.length) {
                restore();
                finish();
                return;
            }
            var script = document.createElement('script');
            script.src = urls[index++];
            script.async = false;
            script.onload = next;
            script.onerror = function () {
                restore();
                // Drop the waiters rather than calling them: window.firebase is
                // absent, and every one would log the same missing-SDK warning.
                firebaseLoading = false;
                firebaseWaiting.length = 0;
                window.console && console.warn('[Retnly] firebase SDK failed to load');
            };
            (document.head || document.body).appendChild(script);
        }());
    }

    /**
     * Show pushes that arrive while the shopper is LOOKING at the storefront.
     *
     * Firebase splits delivery by page visibility. The service worker's
     * onBackgroundMessage runs only when no tab of this origin is focused; with
     * a tab in the foreground the message is handed to the PAGE instead, and a
     * page with no onMessage handler drops it. Silently, and after FCM has
     * already returned 200 and the automation has written a green PushDeliveryLog
     * row — which is what makes this the hardest push fault to diagnose: every
     * server-side signal says delivered.
     *
     * Raised through the service worker's REGISTRATION rather than
     * `new Notification()`. A notification created the latter way never reaches
     * the worker's notificationclick listener, so the tap would neither navigate
     * to click_url nor be attributed. Going through the registration means the
     * one handler in Controller/Sw/Messaging.php serves both paths, and
     * `data.click_url` is the key it reads — hence the shape below.
     */
    function listenForeground(messaging, registration) {
        if (foregroundBound || !registration) {
            return;
        }
        foregroundBound = true;
        try {
            messaging.onMessage(function (payload) {
                var notification = (payload && payload.notification) || {};
                var data = (payload && payload.data) || {};
                // A data-only message is a signal to the page, not something to
                // put on the shopper's screen with an empty body.
                if (!notification.title && !notification.body) {
                    return;
                }
                registration.showNotification(notification.title || 'New message', {
                    body: notification.body || '',
                    icon: notification.icon || data.icon || undefined,
                    data: {
                        click_url: data.click_url || data.url ||
                            notification.click_action || '/'
                    }
                });
            });
        } catch (err) {
            window.console &&
                console.warn('[Retnly] foreground push listener failed', err);
        }
    }

    function registerPush() {
        if (!config.push || !config.push.enabled) {
            return;
        }
        if (!('Notification' in window) || !('serviceWorker' in navigator)) {
            return;
        }
        // Never prompt on first paint -- an unexplained permission dialog is
        // the fastest way for a merchant to lose the permission permanently.
        // Registration resumes only once the shopper has already granted it.
        if (Notification.permission !== 'granted') {
            return;
        }

        loadFirebase(function () {
            // The scripts reported success but left no global. That means they
            // took the AMD branch after all, or the CDN served something other
            // than the SDK — the failure that produced "Script error for
            // @firebase/app-compat" and silently stopped every registration.
            // Say so plainly rather than throwing a TypeError about `apps`.
            if (!window.firebase || !window.firebase.messaging) {
                window.console &&
                    console.warn('[Retnly] firebase SDK loaded but window.firebase is missing');
                return;
            }
            try {
                var app = window.firebase.apps.length
                    ? window.firebase.app()
                    : window.firebase.initializeApp(config.push.firebase);
                var messaging = window.firebase.messaging(app);

                navigator.serviceWorker.register('/retnly/sw/messaging', { scope: '/' })
                    .then(function (registration) {
                        listenForeground(messaging, registration);
                        return messaging.getToken({
                            vapidKey: config.push.vapidKey,
                            serviceWorkerRegistration: registration
                        });
                    })
                    .then(function (token) {
                        if (!token) {
                            return;
                        }
                        pushToken = token;
                        // Attach the fresh token to the next event. A dedicated
                        // "register" call would need its own endpoint; the
                        // receiver already stores visitor.pushToken on whatever
                        // event carries it.
                        send('cart_viewed', identity(), 'push-register-' + token.slice(-12));
                    })
                    ['catch'](function (err) {
                        window.console && console.warn('[Retnly] push registration failed', err);
                    });
            } catch (err) {
                window.console && console.warn('[Retnly] firebase init failed', err);
            }
        });
    }

    /**
     * A dismissible bar offering push notifications, pinned to the bottom.
     *
     * WHY THIS EXISTS
     *   registerPush() refuses to act until Notification.permission is already
     *   'granted', and nothing ever asked — so on a stock storefront permission
     *   could only be granted by a shopper digging through browser settings. In
     *   practice that meant never: no prompt, no token, no push, and nothing in
     *   any log to say so.
     *
     * WHY A BAR RATHER THAN CALLING requestPermission() DIRECTLY
     *   The native dialog fired on page load is what the original comment warns
     *   against, and it is right: an unexplained prompt is usually denied, a
     *   denial is permanent, and browsers penalise sites that do it. So the bar
     *   explains the offer first and only calls requestPermission() from the
     *   shopper's own click — a gesture the browser treats far more kindly.
     *
     * Shown only when permission is still 'default': never after a grant (there
     * is nothing to ask) and never after a denial (the browser would ignore it).
     */
    function showPushOptIn() {
        if (!config.push || !config.push.enabled) {
            return;
        }
        if (!('Notification' in window) || !('serviceWorker' in navigator)) {
            return;
        }
        if (Notification.permission !== 'default') {
            return;
        }
        // storage() returns null in Safari private mode rather than throwing;
        // with no way to remember a dismissal the bar is still worth showing,
        // it just cannot be silenced permanently.
        var store = storage();
        if (store && store.getItem(PUSH_DECLINED_KEY)) {
            return;
        }
        if (document.getElementById('retnly-push-optin')) {
            return;
        }

        var bar = document.createElement('div');
        bar.id = 'retnly-push-optin';
        // Inline styles on purpose: a stylesheet would have to be deployed per
        // theme, and this must render identically on a stock Luma and on a
        // custom frontend that knows nothing about this module.
        bar.setAttribute('style', [
            'position:fixed', 'left:0', 'right:0', 'bottom:0', 'z-index:2147483000',
            'background:#1f2937', 'color:#fff', 'padding:14px 16px',
            'font:14px/1.4 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif',
            'display:flex', 'flex-wrap:wrap', 'gap:12px',
            'align-items:center', 'justify-content:center',
            'box-shadow:0 -2px 12px rgba(0,0,0,.25)'
        ].join(';'));

        var text = document.createElement('span');
        text.textContent = 'Get notified about your order and offers?';
        text.setAttribute('style', 'flex:1 1 auto;min-width:200px');

        var allow = document.createElement('button');
        allow.type = 'button';
        allow.textContent = 'Allow';
        allow.setAttribute('style',
            'background:#fff;color:#1f2937;border:0;border-radius:4px;' +
            'padding:8px 18px;font-weight:600;cursor:pointer');

        var no = document.createElement('button');
        no.type = 'button';
        no.textContent = 'No thanks';
        no.setAttribute('style',
            'background:transparent;color:#d1d5db;border:0;' +
            'padding:8px 12px;cursor:pointer;text-decoration:underline');

        function close() {
            if (bar.parentNode) {
                bar.parentNode.removeChild(bar);
            }
        }

        allow.onclick = function () {
            close();
            try {
                var result = Notification.requestPermission(function (perm) {
                    // Legacy callback form, for browsers that do not return a
                    // promise. Harmless where both exist.
                    if (perm === 'granted') {
                        registerPush();
                    }
                });
                if (result && typeof result.then === 'function') {
                    result.then(function (perm) {
                        if (perm === 'granted') {
                            registerPush();
                        }
                    });
                }
            } catch (e) {
                // A blocked or unavailable permission API must never break the page.
            }
        };

        no.onclick = function () {
            close();
            try {
                if (store) {
                    store.setItem(PUSH_DECLINED_KEY, '1');
                }
            } catch (e) {
                // Private mode or a full quota: the bar reappears next visit,
                // which is a better failure than breaking the page.
            }
        };

        bar.appendChild(text);
        bar.appendChild(allow);
        bar.appendChild(no);
        document.body.appendChild(bar);
    }

    return function (settings) {
        config = settings || {};
        if (!config.endpoint || !config.storeDomain) {
            return;
        }

        // Deferred so it never competes with first paint, and so a shopper who
        // is still reading the page is not interrupted the instant it loads.
        window.setTimeout(showPushOptIn, 4000);

        var cart = customerData.get('cart');
        var lastCount = null;

        // Magento has no add-to-cart DOM event that survives every theme, but
        // the customer-data cart section is refreshed by core on every cart
        // mutation regardless of theme. Watching the count is therefore the one
        // signal that works on a stock install and on a custom frontend alike.
        cart.subscribe(function (updated) {
            var count = (updated && updated.summary_count) || 0;
            var payload = $.extend({}, identity(), cartPayload(updated));

            if (lastCount === null) {
                lastCount = count;
                if (count > 0 && window.location.pathname.indexOf('/checkout/cart') !== -1) {
                    send('cart_viewed', payload, 'cart-view-' + count);
                }
                return;
            }

            if (count > lastCount) {
                send('product_added_to_cart', payload);
            } else if (count < lastCount) {
                send('product_removed_from_cart', payload);
            }
            lastCount = count;
        });

        var path = window.location.pathname;
        var initialCart = cart();
        var initialPayload = $.extend({}, identity(), cartPayload(initialCart));

        // Fired once per product page load. The id comes from the view model
        // (the request's product id), not from the DOM, because no markup is
        // stable across themes. Deduped on the id so Magento's private-content
        // refresh — which re-runs this script — is not counted as a second view.
        if (config.productId) {
            send('product_viewed',
                 $.extend({}, identity(), { product_id: String(config.productId) }),
                 'product-view-' + config.productId);
        }

        if (path.indexOf('/checkout/onepage/success') !== -1) {
            send('checkout_completed', initialPayload, 'checkout-complete-' + path);
        } else if (path.indexOf('/checkout') !== -1) {
            send('checkout_started', initialPayload, 'checkout-start-' + (initialCart.summary_count || 0));
        } else if (path.indexOf('/checkout/cart') !== -1) {
            send('cart_viewed', initialPayload, 'cart-view-' + (initialCart.summary_count || 0));
        }

        registerPush();
    };
});
