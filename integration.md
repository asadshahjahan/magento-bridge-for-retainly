# Connect Your Magento Store to Retnly

This takes about 15 minutes. Steps 1–3 are run on your Magento server, steps 4–5
in your Retnly dashboard, and step 6 in your Magento admin panel.

**You will need:**

- Magento 2.4 or newer, on PHP 8.1 or newer
- SSH access to your Magento server
- A Magento admin account with Sales permissions
- Your Retnly account login

Throughout, `/path/to/magento` means wherever Magento is installed on your server
(commonly `/var/www/magento` or `/var/www/html`).

---

## Getting help while you set this up

If you get stuck, you can use ChatGPT, Gemini or Claude as a guide. Copy the block
below into a new chat, then copy **this entire guide** underneath it so the
assistant has the real instructions to work from.

```text
You are a careful technical assistant helping me connect my Magento 2 store to
Retnly, a customer-marketing platform. I am following Retnly's official setup
guide, which I have pasted at the end of this message.

HOW TO HELP ME

- Answer from the guide. It is the authoritative source. If the guide covers my
  question, point me to the step by number and quote the exact command.
- If the guide does NOT cover it, say so plainly rather than guessing, and tell
  me to email support@zeroslip.co. A wrong command on a live store is far worse
  than "I don't know".
- I am not necessarily a developer. Explain what a command does before I run it,
  in plain language, and tell me what a successful result looks like.
- Ask which step I am on if it is not obvious. Do not assume I have completed
  earlier steps.
- Before any command that deletes, drops or overwrites anything, say so clearly
  and tell me what I would lose.

THINGS YOU MUST NOT INVENT

- The Retnly API Base URL. Use exactly the URL written in the guide, or the one
  Retnly gave me directly. Never guess it from the Retnly website or from my
  store's domain. A wrong value here looks like success — Magento saves, the
  connection reports connected, orders send with no error — but the data goes
  to the wrong place and my dashboard stays empty with nothing to explain why.
- My Retnly API Key, my Magento admin token, or any password. If you need one,
  tell me where in the guide to find it.
- Magento commands that are not in the guide. If you think one is needed,
  explain why and tell me to confirm with Retnly support first.

WHAT I WILL PASTE

I may paste terminal output or error messages. When I do:
- Match it against the guide's "If something is not working" section first.
- Quote the fix from the guide verbatim rather than writing your own version.
- If it is not in that section, tell me it is not covered and to contact support.

THINGS THAT COMMONLY GO WRONG — ask about these if my symptoms match

1. Storefront shows "An error has happened during application run" after Step 1.
   Almost always because Step 1 was run as root and file ownership was not
   restored. The guide has the exact fix.
2. Step 1 stops with "allowed memory size exhausted". Common on small servers;
   the guide shows how to re-run with the memory limit lifted.
3. A test order does not appear. The most common cause is Magento cron not
   running (Step 2), followed by Step 6 not being saved.
4. The storefront looks unchanged, or abandoned carts are not tracked. Static
   content usually needs redeploying.
5. The dashboard rejects my Magento token. Tokens expire after an hour unless
   both commands in Step 3 were run.

Start by asking me which step I am on and whether anything has failed yet. Then
wait for my answer before giving instructions.

--- RETNLY SETUP GUIDE BELOW THIS LINE ---

[PASTE THE FULL CONTENTS OF THIS GUIDE HERE]
```

---

## Step 1 — Install the Retnly module

SSH into your Magento server and run:

```bash
cd /path/to/magento
composer require retnly/magento-sync
bin/magento module:enable Retnly_MagentoBridge
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

Then give the files back to your web server user. On most servers that user is
`www-data`:

```bash
chown -R www-data:www-data var generated pub/static
```

**Do not skip this if you ran the commands above as root.** `setup:upgrade`
deletes Magento's generated code and the web server rebuilds it on the next page
load — but it cannot, if root owns those folders, and your storefront will show an
error page until you fix it. If you are not sure which user your web server runs
as, this will tell you:

```bash
ps -o user= -C php-fpm | sort -u
```

Two things to expect while this runs, both normal:

- Composer warns that some `laminas/...` and `doctrine/annotations` packages are
  abandoned. Those are Magento's own dependencies, not Retnly's. Ignore them.
- `setup:di:compile` and `setup:static-content:deploy` each take several minutes
  and print nothing while they work. They are not stuck. Let them finish.

Confirm it installed:

```bash
bin/magento module:status Retnly_MagentoBridge
```

It should say the module is enabled.

---

## Step 2 — Check that Magento cron is running

Retnly sends your data using Magento's own cron, so it has to be running:

```bash
crontab -l | grep magento
```

If that prints nothing, set it up:

```bash
bin/magento cron:install
```

Cron needs to run every minute. If it is not running, your orders are saved but
never sent to Retnly, and you will see no error telling you why.

---

## Step 3 — Create your Magento API token

First, stop the token from expiring. Magento expires admin tokens after one hour
by default, which would break your syncing an hour after you set it up:

```bash
cd /path/to/magento
bin/magento config:set webapi/jwtauth/admin_expiration 525600
bin/magento config:set oauth/access_token_lifetime/admin 8760
bin/magento cache:flush
```

Run both lines — they apply to different Magento versions, and running only one
may leave you on the one-hour default.

Now create the token. Replace the store address, username and password below with
your own, and run it as a single line:

```bash
curl -X POST https://your-store.com/rest/V1/integration/admin/token -H "Content-Type: application/json" -d '{"username":"your_admin_username","password":"your_admin_password"}'
```

This prints a long token in quotes. Copy it, **without the quotes** — you need it
in Step 5.

If it returns `Could not resolve host`, the store address above was not replaced
with yours. If it says the account is locked, clear it with
`bin/magento admin:user:unlock your_admin_username` and try again.

---

## Step 4 — Copy your Retnly API Key

Log in to your Retnly dashboard and go to **Integrations → Magento**.

At the top of the page you will see your Retnly API Key. Copy it now. Once you
connect your store in the next step, this key is no longer shown on screen.

---

## Step 5 — Connect your store

On the same **Integrations → Magento** page, fill in:

| Field | Value |
|---|---|
| Store URL | Your store address, with no slash at the end. Example: `https://your-store.com` |
| Magento API Token | The token you copied in Step 3 |

Click **Connect Magento Store**. The page should change to show Connected, with
your store URL underneath.

---

## Step 6 — Switch the integration on in Magento

In your Magento admin panel, go to:

**Stores → Configuration → Retnly → General**

Set:

| Field | Value |
|---|---|
| Enable Retnly Integration | Yes |
| Retnly API Key | The key you copied in Step 4 |
| Retnly Store ID | Leave blank. Retnly fills this in for you. |
| Retnly API Base URL | `https://public.api.zeroslip.co/api/v4/magento/` |

### IMPORTANT — do not skip the API Base URL

The field is **not** already correct. It arrives holding a testing address, and a
store left on it will appear to work perfectly: Magento saves, the connection
succeeds, orders send without error. They simply go to our testing system instead
of your account, so your Retnly dashboard stays empty and nothing tells you why.

Replace the whole field with the address above, exactly as written, including the
trailing slash. **If Retnly gave you a different address, use that one instead.**

Click **Save Config**. Then, back on your server:

```bash
cd /path/to/magento
bin/magento cache:flush
```

Your store is now connected. Nothing is sent to Retnly until this step is saved,
so do not skip it.

---

## Step 7 — Check that it works

Place a test order on your store. Within about a minute it should appear in your
Retnly dashboard.

To bring in orders from before today, go to **Integrations → Magento**, choose a
start date under *Sync Your Magento Data*, and click **Start Import**. Retnly only
sees orders placed after Step 1, so this is the only way to load your history.

---

## If something is not working

### My storefront now shows "An error has happened during application run"

This means Magento's generated code was cleared and cannot be rebuilt, almost
always because Step 1 was run as root. Fix it with:

```bash
cd /path/to/magento
rm -rf generated/code generated/metadata
bin/magento setup:di:compile
chown -R www-data:www-data var generated pub/static
bin/magento cache:flush
```

Your store should load again straight away. To confirm the cause, look at the last
few lines of `var/log/exception.log` — a message about a class ending in
`\Interceptor` not existing is this exact problem.

### Step 1 stopped with an "allowed memory size exhausted" error

Common on smaller servers. Re-run the command that failed with the memory limit
lifted, for example:

```bash
php -d memory_limit=-1 bin/magento setup:di:compile
```

Then carry on with the remaining commands in Step 1.

### Step 1 failed part-way through for any other reason

It is safe to re-run the whole block from the top. None of those commands cause a
problem by being run twice.

### My test order has not appeared after a few minutes

Check Step 6 was saved with **Enable Retnly Integration** set to Yes, and that the
API Key matches the one from Step 4 exactly, with no extra spaces. Then check cron
is running (Step 2) — this is the most common cause.

### The dashboard would not accept my token

Tokens expire after an hour unless you ran **both** commands in Step 3. Create a
fresh token and try again. If it is still refused, check that your Magento admin
user has Sales permissions.

### Live orders arrive, but Start Import does nothing

Importing needs Retnly to reach your store from the internet. If your store is
only reachable from inside your network, or sits behind a VPN, ask your hosting
provider to allow inbound access.

### My storefront looks unchanged and abandoned carts are not tracked

Re-run `bin/magento setup:static-content:deploy -f` and then
`bin/magento cache:flush`.

### Anything else

Email **support@zeroslip.co** with your store URL and roughly when you connected,
and we can see the delivery attempts from our side.

---

## What happens now that you are connected

- New orders reach Retnly within about a minute
- New customer registrations sync automatically
- Abandoned carts sync every hour, with no duplicate messages
- Your checkout speed is unaffected — all syncing happens in the background, and
  Retnly being briefly unavailable can never slow down or block a sale

You can now build automations in Retnly under **Automations**, triggered by any of
these Magento events:

| Event | Fires when |
|---|---|
| `magento_order_placed` | a new order is placed |
| `magento_order_paid` | an invoice is created (payment confirmed) |
| `magento_order_fulfilled` | the order is shipped and complete |
| `magento_order_cancelled` | an order is cancelled |
| `magento_order_refunded` | a credit memo is issued |
| `magento_cart_abandoned` | a cart has sat idle for over an hour (Email and Push only) |

---

Need a hand at any point: **support@zeroslip.co**
