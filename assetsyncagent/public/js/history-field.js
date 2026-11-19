$(() => {
    function updateHistoryFieldLabels()
    {
        for (const entry of this.querySelectorAll('[data-asset-sync-history-field]'))
        {
            // In GLPI's Historical tab, the Field cell comes just before the Update cell.
            const updateCell = entry.closest('td[colspan="2"]');
            const fieldCell = updateCell?.previousElementSibling;
            if (fieldCell?.tagName === 'TD')
            {
                fieldCell.textContent = entry.dataset.assetSyncHistoryField;
            }
        }
    }

    // GLPI loads the Historical tab and reloads its filters and paging over AJAX.
    $('main').each(updateHistoryFieldLabels).on('glpi.tab.loaded', updateHistoryFieldLabels);
});
