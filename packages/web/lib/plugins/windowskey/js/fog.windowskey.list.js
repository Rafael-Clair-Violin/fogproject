(function($) {
    var deleteSelected = $('#deleteSelected'),
        deleteModal = $('#deleteModal'),
        passwordField = $('#deletePassword'),
        confirmDelete = $('#confirmDeleteModal'),
        cancelDelete = $('#closeDeleteModal'),
        numWindowsKeyString = confirmDelete.val();

    function disableButtons(disable) {
        deleteSelected.prop('disabled', disable);
    }
    function onSelect(selected) {
        var disabled = selected.count() == 0;
        disableButtons(disabled);
    }

    disableButtons(true);
    var table = Common.registerTable($('#dataTable'), onSelect, {
        order: [
            [0, 'asc']
        ],
        columns: [
            {data: 'name'},
            {data: 'key'}
        ],
        rowId: 'id',
        columnDefs: [
            {
                responsivePriority: -1,
                render: function(data, type, row) {
                    return '<a href="../management/index.php?node='
                        + Common.node
                        + '&sub=edit&id='
                        + row.id
                        + '">'
                        + data
                        + '</a>';
                },
                targets: 0,
            }
        ],
        processing: true,
        serverSide: true,
        ajax: {
            url: '../management/index.php?node='
            + Common.node
            + '&sub=list',
            type: 'post'
        }
    });

    if (Common.search && Common.search.length > 0) {
        table.search(Common.search).draw();
    }

    deleteSelected.on('click',function() {
        disableButtons(true);
        confirmDelete.val(numWindowsKeyString.format(''));
        Common.massDelete(null, function(err) {
            if (err.status == 401) {
                deleteModal.modal('show');
            } else {
                onSelect(table.rows({selected: true}));
            }
        }, table);
    });
})(jQuery);
