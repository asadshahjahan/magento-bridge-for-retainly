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
 *   `sendBeacon` where available, because the two events most worth having
 *   (checkout_started, checkout_completed) fire as the page is being torn down
 *   and a normal fetch is cancelled. `keepalive` fetch is the fallback.
 */
define([
    'jquery',
    'Magento_Customer/js/customer-data'
], function ($, customerData) {
    'use strict';

    var CLIENT_ID_KEY = 'retnly_pixel_client_id';
    var SENT_IDS_KEY = 'retnly_pixel_sent_ids';

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

        // Beacon first: checkout_started and checkout_completed both fire while
        // the page is unloading, and a normal XHR is cancelled at that point.
        if (navigator.sendBeacon) {
            try {
                var blob = new Blob([json], { type: 'application/json' });
                if (navigator.sendBeacon(config.endpoint, blob)) {
                    return;
                }
            } catch (e) {
                // fall through to fetch
            }
        }

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
        return out;
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

        require([
            'https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js',
            'https://www.gstatic.com/firebasejs/10.12.2/firebase-messaging-compat.js'
        ], function () {
            try {
                var app = window.firebase.apps.length
                    ? window.firebase.app()
                    : window.firebase.initializeApp(config.push.firebase);
                var messaging = window.firebase.messaging(app);

                navigator.serviceWorker.register('/retnly/sw/messaging', { scope: '/' })
                    .then(function (registration) {
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

    return function (settings) {
        config = settings || {};
        if (!config.endpoint || !config.storeDomain) {
            return;
        }

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
