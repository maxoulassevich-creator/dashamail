/* global amaDashaMail, dashamail, jQuery */
(function (window, document, $) {
    'use strict';

    var cfg = window.amaDashaMail || {};
    var deliveredKey = 'ama_dm_delivered_event_ids_v1';
    var delivered = [];

    try {
        delivered = JSON.parse(window.sessionStorage.getItem(deliveredKey) || '[]');
        if (!Array.isArray(delivered)) delivered = [];
    } catch (e) {
        delivered = [];
    }

    function debug() {
        if (!cfg.debug || !window.console || !window.console.log) return;
        var args = Array.prototype.slice.call(arguments);
        args.unshift('[AMA DashaMail]');
        window.console.log.apply(window.console, args);
    }

    function remember(id) {
        if (!id) return;
        delivered.push(String(id));
        delivered = delivered.slice(-200);
        try {
            window.sessionStorage.setItem(deliveredKey, JSON.stringify(delivered));
        } catch (e) {}
    }

    function wasDelivered(id) {
        return id && delivered.indexOf(String(id)) !== -1;
    }

    function orderDedupeKey(event) {
        if (!event || !event.payload || !event.payload.operation) return '';
        var operation = event.payload.operation;
        var order = event.payload.data && event.payload.data.order ? event.payload.data.order : null;
        if (!order || !order.orderId || ['OrderCreated', 'OrderPaid', 'OrderClosed'].indexOf(operation) === -1) return '';
        return 'ama_dm_order_' + operation + '_' + String(order.orderId);
    }

    function orderWasDelivered(event) {
        var key = orderDedupeKey(event);
        if (!key) return false;
        try { return window.localStorage.getItem(key) === '1'; } catch (e) { return false; }
    }

    function rememberOrder(event) {
        var key = orderDedupeKey(event);
        if (!key) return;
        try { window.localStorage.setItem(key, '1'); } catch (e) {}
    }

    function ensureTrackerQueue() {
        window.dashamail = window.dashamail || function () {
            (window.dashamail.queue = window.dashamail.queue || []).push(arguments);
        };
        window.dashamail.queue = window.dashamail.queue || [];
    }

    function sendEvent(event) {
        if (!event || !event.command || wasDelivered(event.id) || orderWasDelivered(event)) return;
        ensureTrackerQueue();
        var command = String(event.command);
        var payload = event.payload || {};
        try {
            if (command === 'cart.clear' && (!payload || Object.keys(payload).length === 0)) {
                window.dashamail('cart.clear');
            } else {
                window.dashamail(command, payload);
            }
            remember(event.id);
            rememberOrder(event);
            debug('sent', command, payload, event.source || '');
        } catch (error) {
            debug('send error', error, event);
        }
    }

    function sendEvents(events) {
        if (!Array.isArray(events)) return;
        events.forEach(sendEvent);
    }

    function identity(email) {
        email = String(email || '').trim().toLowerCase();
        if (!isValidEmail(email)) return false;
        ensureTrackerQueue();
        var key = 'ama_dm_identified_' + email;
        try {
            if (window.sessionStorage.getItem(key) === '1') return true;
        } catch (e) {}
        window.dashamail('identity', {
            operation: 'UserIdentifier',
            identifier: { profile: 'email', identity: email }
        });
        try { window.sessionStorage.setItem(key, '1'); } catch (e) {}
        debug('identified', email);
        return true;
    }

    function subscribe(email) {
        var point = String(cfg.pointOfContact || '').trim();
        if (!point || !isValidEmail(email)) return;
        var key = 'ama_dm_subscribed_' + point + '_' + String(email).toLowerCase();
        try {
            if (window.localStorage.getItem(key) === '1') return;
        } catch (e) {}
        ensureTrackerQueue();
        window.dashamail('async', {
            operation: 'UserSubscribe',
            data: {
                customer: { email: String(email).trim().toLowerCase() },
                pointOfContact: point
            }
        });
        try { window.localStorage.setItem(key, '1'); } catch (e) {}
        debug('subscribed', email, point);
    }

    function snapshotHash(items) {
        try { return JSON.stringify(items || []); } catch (e) { return ''; }
    }

    function syncCartSnapshot(force) {
        var items = Array.isArray(cfg.cartSnapshot) ? cfg.cartSnapshot : [];
        var hash = snapshotHash(items);
        var key = 'ama_dm_cart_snapshot_' + hash;
        try {
            if (!force && window.sessionStorage.getItem(key) === '1') return;
        } catch (e) {}
        ensureTrackerQueue();
        window.dashamail('cart.clear');
        items.forEach(function (item) {
            if (!item || !item.productId || !item.quantity) return;
            window.dashamail('cart.addProduct', {
                productId: String(item.productId),
                quantity: Number(item.quantity),
                price: String(item.price || '0')
            });
        });
        try { window.sessionStorage.setItem(key, '1'); } catch (e) {}
        debug('cart snapshot synced', items);
    }

    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(email || '').trim());
    }

    function marketingConsentGranted() {
        if (!cfg.requireMarketingConsent) return true;
        var selector = String(cfg.marketingCheckboxSelector || '').trim();
        if (!selector) return false;
        var node = document.querySelector(selector);
        return !!(node && node.checked);
    }

    function identifyCheckout() {
        if (!cfg.identifyCheckoutEmail) return;
        var emailInput = document.querySelector('#billing_email, input[name="billing_email"]');
        if (!emailInput) return;
        var email = String(emailInput.value || '').trim().toLowerCase();
        if (!isValidEmail(email) || !marketingConsentGranted()) return;
        if (identity(email)) {
            subscribe(email);
            syncCartSnapshot(false);
        }
    }

    function pullEvents() {
        if (!cfg.ajaxUrl || !cfg.nonce) return;
        $.ajax({
            url: cfg.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: { action: 'ama_dm_pull_events', nonce: cfg.nonce }
        }).done(function (response) {
            if (response && response.success && response.data) {
                sendEvents(response.data.events || []);
            }
        }).fail(function (xhr) {
            debug('pull failed', xhr && xhr.status);
        });
    }

    function bindWooEvents() {
        $(document.body).on(
            'added_to_cart removed_from_cart updated_wc_div updated_cart_totals applied_coupon_in_checkout removed_coupon_in_checkout updated_checkout',
            function () { window.setTimeout(pullEvents, 80); }
        );

        $(document.body).on('checkout_place_order_success', function (event, result) {
            if (result && Array.isArray(result.ama_dm_events)) {
                sendEvents(result.ama_dm_events);
            }
        });

        $(document).on('found_variation', 'form.variations_form', function (event, variation) {
            if (!variation || !variation.ama_dm_product_id) return;
            sendEvent({
                id: 'variation-view-' + variation.variation_id + '-' + Date.now(),
                command: 'async',
                source: 'variation_selected',
                payload: {
                    operation: 'ViewProduct',
                    data: {
                        product: {
                            productId: String(variation.ama_dm_product_id),
                            price: String(variation.ama_dm_price || variation.display_price || '0')
                        }
                    }
                }
            });
        });
    }

    function bindCheckoutIdentity() {
        if (!cfg.identifyCheckoutEmail) return;
        $(document).on('change blur', '#billing_email, input[name="billing_email"]', function () {
            window.setTimeout(identifyCheckout, 50);
        });
        if (cfg.marketingCheckboxSelector) {
            $(document).on('change', cfg.marketingCheckboxSelector, function () {
                window.setTimeout(identifyCheckout, 50);
            });
        }
        $(document.body).on('updated_checkout', function () {
            window.setTimeout(identifyCheckout, 100);
        });
    }

    $(function () {
        ensureTrackerQueue();
        sendEvents(cfg.directEvents || []);
        sendEvents(cfg.orderEvents || []);

        if (cfg.currentUserEmail && identity(cfg.currentUserEmail)) {
            syncCartSnapshot(false);
        }

        bindWooEvents();
        bindCheckoutIdentity();
        pullEvents();
        identifyCheckout();
    });
})(window, document, jQuery);
