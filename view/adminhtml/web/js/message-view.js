/**
 * Modal body viewer for the inbox grid.
 *
 * The message body is third-party content: it holds API error payloads that can contain
 * anything the remote system echoed back, including markup. It is therefore bound with
 * Knockout's text binding throughout, never html, so the browser sets textContent and
 * never parses it. There is no HTML binding anywhere in this module.
 */
define([
    'uiComponent',
    'ko',
    'jquery',
    'underscore',
    'uiRegistry',
    'mage/translate'
], function (Component, ko, $, _, registry, $t) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'DeployEcommerce_Inbox/message-view',
            viewUrl: '',
            setStatusUrl: '',
            listingProvider: '',
            modules: {
                modal: '${ $.parentName }'
            }
        },

        initObservable: function () {
            this._super().observe([]);

            this.loading = ko.observable(false);
            this.error = ko.observable('');
            this.messageId = ko.observable(null);
            this.title = ko.observable('');
            this.severityLabel = ko.observable('');
            this.source = ko.observable('');
            this.createdAt = ko.observable('');
            this.lastSeenAt = ko.observable('');
            this.occurrences = ko.observable(0);
            this.neverDelete = ko.observable(false);
            this.tags = ko.observableArray([]);
            this.body = ko.observable('');
            this.truncated = ko.observable(false);
            this.size = ko.observable(0);
            this.copied = ko.observable(false);

            return this;
        },

        /**
         * Called by the actions column, both from the View link and from a row click.
         *
         * The actions column passes a configured action's params through as a single
         * argument, so this receives the array [id] rather than the id itself. Both forms
         * are accepted so the method is also callable directly.
         */
        openMessage: function (messageId) {
            var self = this,
                id = _.isArray(messageId) ? messageId[0] : messageId;

            if (!id) {
                this.error($t('No message was selected.'));

                return;
            }

            this.reset();
            this.loading(true);

            if (this.modal()) {
                this.modal().openModal();
            }

            $.ajax({
                url: this.viewUrl,
                type: 'GET',
                dataType: 'json',
                data: {id: id},
                showLoader: false
            }).done(function (data) {
                if (!data || data.error) {
                    self.error(data && data.error ? data.error : $t('The message could not be loaded.'));

                    return;
                }

                self.messageId(data.message_id);
                self.title(data.title || '');
                self.severityLabel(data.severity_label || '');
                self.source(data.source || '');
                self.createdAt(data.created_at || '');
                self.lastSeenAt(data.last_seen_at || '');
                self.occurrences(data.occurrences || 0);
                self.neverDelete(!!data.never_delete);
                self.tags(data.tags || []);
                self.body(data.body || '');
                self.truncated(!!data.truncated);
                self.size(data.size || 0);

                // Opening a message is what "read" means in an inbox; asking the user to
                // also click Mark as Read afterwards is busywork. Unread is recoverable
                // from the row action and the mass action.
                if (!data.is_read) {
                    self.setStatus(data.message_id, 'read');
                }
            }).fail(function (jqXHR) {
                // Surface what the server actually said. Swallowing it behind a generic
                // string is what made the original argument-shape bug hard to place.
                var reported = jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.error;

                self.error(reported || $t('The message could not be loaded.'));
            }).always(function () {
                self.loading(false);
            });
        },

        setStatus: function (messageId, status) {
            var self = this;

            $.ajax({
                url: this.setStatusUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    id: messageId,
                    status: status,
                    // Required: the admin router rejects a POST without it. Never bypass this
                    // by making the controller CSRF-exempt.
                    form_key: window.FORM_KEY
                },
                showLoader: false
            }).done(function () {
                self.updateRow(messageId, status === 'read');
            });
        },

        /**
         * Update the row in place rather than reloading the grid. A full reload on every
         * modal open is jarring and loses scroll position; it is only needed when the row
         * must disappear because a status filter is active.
         */
        updateRow: function (messageId, isRead) {
            var provider = this.listingProvider ? registry.get(this.listingProvider) : null,
                items,
                filtered;

            if (!provider) {
                return;
            }

            items = provider.data && provider.data.items ? provider.data.items : [];

            items.forEach(function (item) {
                if (parseInt(item.message_id, 10) === parseInt(messageId, 10)) {
                    item.is_read = isRead ? 1 : 0;
                }
            });

            filtered = provider.params && provider.params.filters ? provider.params.filters : {};

            if (Object.prototype.hasOwnProperty.call(filtered, 'is_read')) {
                provider.reload({refresh: true});
            }
        },

        markUnread: function () {
            if (this.messageId()) {
                this.setStatus(this.messageId(), 'unread');
                this.closeModal();
            }
        },

        closeModal: function () {
            if (this.modal()) {
                this.modal().closeModal();
            }
        },

        /**
         * Copy the body to the clipboard.
         *
         * The asynchronous clipboard API is only available in a secure context, which
         * production admin is but a plain-http local environment is not, so there is a
         * fallback. Both read the observable directly rather than scraping the DOM, so the
         * copied text is byte-faithful and nothing round-trips through HTML parsing.
         */
        copyBody: function () {
            var self = this,
                text = this.body();

            if (!text) {
                return;
            }

            if (window.isSecureContext && navigator.clipboard) {
                navigator.clipboard.writeText(text).then(
                    function () {
                        self.flashCopied();
                    },
                    function () {
                        self.legacyCopy(text);
                    }
                );

                return;
            }

            this.legacyCopy(text);
        },

        legacyCopy: function (text) {
            var textarea = document.createElement('textarea');

            textarea.value = text;
            textarea.setAttribute('readonly', 'readonly');
            // Must be in the document and visible to the selection API; offscreen and
            // transparent rather than display:none, which cannot be selected.
            textarea.style.cssText = 'position:fixed;top:0;left:0;opacity:0;';
            document.body.appendChild(textarea);
            textarea.select();
            textarea.setSelectionRange(0, textarea.value.length);

            try {
                document.execCommand('copy');
                this.flashCopied();
            } catch (e) {
                this.error($t('Could not copy to the clipboard.'));
            }

            document.body.removeChild(textarea);
        },

        flashCopied: function () {
            var self = this;

            this.copied(true);
            setTimeout(function () {
                self.copied(false);
            }, 1500);
        },

        reset: function () {
            this.error('');
            this.title('');
            this.body('');
            this.tags([]);
            this.truncated(false);
            this.copied(false);
        }
    });
});
