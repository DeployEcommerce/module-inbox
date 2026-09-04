define(['Magento_Ui/js/grid/columns/select'], function (Select) {
    'use strict';

    return Select.extend({
        defaults: {
            bodyTmpl: 'DeployEcommerce_Inbox/grid/cells/severity'
        },

        /**
         * Build the badge class from a whitelist of characters only. The label originates
         * from our own enum, but deriving a class name from row data without stripping is
         * the kind of thing that becomes an injection when the data source changes.
         */
        getSeverityClass: function (row) {
            var label = String(row.severity_label || 'unknown').toLowerCase();

            return 'de-inbox-badge de-inbox-badge-' + label.replace(/[^a-z0-9_-]/g, '');
        },

        getSeverityLabel: function (row) {
            return row.severity_label || '';
        }
    });
});
