define(['Magento_Ui/js/grid/columns/column'], function (Column) {
    'use strict';

    return Column.extend({
        defaults: {
            bodyTmpl: 'DeployEcommerce_Inbox/grid/cells/tags'
        },

        getTags: function (row) {
            var tags = row.tags;

            if (!tags) {
                return [];
            }

            return Array.isArray(tags) ? tags : String(tags).split(',');
        }
    });
});
