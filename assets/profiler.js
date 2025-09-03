window.WP_Hook_Profiler = (function($) {
    'use strict';
    
    let profileData = null;
    let currentSort = { column: null, direction: 'desc' };
    
    function init() {
        $(document).ready(function() {
            bindEvents();
            profileData = window.hook_profiler_data;
        });
    }
    
    function bindEvents() {
        $('#wp-hook-profiler-close, #wp-hook-profiler-overlay').on('click', hide);
        
        $('.wp-hook-profiler-tab').on('click', function() {
            const tabName = $(this).data('tab');
            switchTab(tabName);
        });
        
        $(document).on('click', '.wp-hook-profiler-table th.sortable', function() {
            const column = $(this).data('sort');
            sortTable(column, $(this).closest('table'));
        });
        
        $('#wp-hook-profiler-search-plugins').on('input', debounce(filterPluginsTable, 300));
        $('#wp-hook-profiler-search-callbacks').on('input', debounce(filterCallbacksTable, 300));
        $('#wp-hook-profiler-search-hooks, #wp-hook-profiler-filter-plugin').on('input change', debounce(filterHooksList, 300));
        $('#wp-hook-profiler-search-plugin-loading, #wp-hook-profiler-filter-loading-type').on('input change', debounce(filterPluginLoadingTable, 300));
        
        $(document).on('click', '.wp-hook-profiler-plugin-link', function(e) {
            e.preventDefault();
            const pluginName = $(this).data('plugin');
            switchToHooksTabWithPlugin(pluginName);
        });
        
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && isVisible()) {
                hide();
            }
        });
    }
    
    function toggle() {
        if (isVisible()) {
            hide();
        } else {
            show();
        }
    }
    
    function show() {
        $('#wp-hook-profiler-panel, #wp-hook-profiler-overlay').show();
        loadProfileData();

    }
    
    function hide() {
        $('#wp-hook-profiler-panel, #wp-hook-profiler-overlay').hide();
    }
    
    function isVisible() {
        return $('#wp-hook-profiler-panel').is(':visible');
    }
    
    function loadProfileData() {
        showLoading();

        updateSummary();
        updateAllTables();
        hideLoading();
    }
    
    function showLoading() {
        $('.wp-hook-profiler-loading').show();
        $('.wp-hook-profiler-content').hide();
    }
    
    function hideLoading() {
        $('.wp-hook-profiler-loading').hide();
        $('.wp-hook-profiler-content').show();
    }
    
    function showError(message) {
        const errorEl = $('#wp-hook-profiler-error');
        if (message) {
            $('#wp-hook-profiler-error-message').text(message);
            errorEl.show();
        } else {
            errorEl.hide();
        }
    }
    
    function updateSummary() {
        if (!profileData) return;
        
        $('#wp-hook-profiler-total-hooks').text(profileData.total_hooks.toLocaleString());
        $('#wp-hook-profiler-total-time').text((profileData.total_execution_time).toFixed(2));
    }
    
    function updateAllTables() {
        updatePluginsTable();
        updateCallbacksTable();
        updateHooksList();
        updatePluginLoadingTable();
        populatePluginFilter();
    }
    
    function updatePluginsTable() {
        if (!profileData?.plugins) return;
        
        const tbody = $('#wp-hook-profiler-plugins-table');
        const plugins = Object.values(profileData.plugins);
        
        tbody.empty();
        
        plugins.forEach(plugin => {
            const avgTime = plugin.callback_count > 0 ? (plugin.total_time / plugin.callback_count) : 0;
            const timeClass = getTimeColorClass(plugin.total_time);
            
            const row = $(`
                <tr>
                    <td><a href="#" class="wp-hook-profiler-plugin-link" data-plugin="${escapeHtml(plugin.plugin_name)}">${escapeHtml(plugin.plugin_name)}</a></td>
                    <td class="numeric ${timeClass}">${(plugin.total_time).toFixed(3)}</td>
                    <td class="numeric">${plugin.hook_count}</td>
                    <td class="numeric">${plugin.callback_count}</td>
                    <td class="numeric">${avgTime.toFixed(3)}</td>
                </tr>
            `);
            
            tbody.append(row);
        });
    }
    
    function updateCallbacksTable() {
        if (!profileData?.callbacks) return;
        
        const tbody = $('#wp-hook-profiler-callbacks-table');
        
        tbody.empty();
        
        profileData.callbacks.forEach(callback => {
            const timeMs = callback.total_time;
            const timeClass = getTimeColorClass(timeMs);
            
            const row = $(`
                <tr>
                    <td><span class="wp-hook-profiler-callback-name" title="${escapeHtml(callback.source_file)}">${escapeHtml(callback.callback)}</span></td>
                    <td><span class="wp-hook-profiler-hook-name">${escapeHtml(callback.hook)}</span></td>
                    <td><span class="wp-hook-profiler-plugin-name">${escapeHtml(callback.plugin_name || callback.plugin)}</span></td>
                    <td class="numeric ${timeClass}">${timeMs.toFixed(3)}</td>
                    <td class="numeric">${callback.call_count}</td>
                </tr>
            `);
            
            tbody.append(row);
        });
    }
    
    function updateHooksList() {
        if (!profileData?.callbacks) return;
        
        const container = $('#wp-hook-profiler-hooks-list');
        const hookGroups = groupCallbacksByHook(profileData.callbacks);
        
        container.empty();
        
        Object.entries(hookGroups).forEach(([hookName, callbacks]) => {
            const totalTime = callbacks.reduce((sum, cb) => sum + (cb.total_time || 0), 0) * 1000;
            const timeClass = getTimeColorClass(totalTime);
            
            const hookGroup = $(`
                <div class="wp-hook-profiler-hook-group" data-hook="${hookName}">
                    <div class="wp-hook-profiler-hook-header">
                        ${escapeHtml(hookName)} 
                        <span class="${timeClass}" style="float: right;">${isNaN(totalTime) ? '0.000' : totalTime.toFixed(3)}ms</span>
                    </div>
                    <div class="wp-hook-profiler-hook-callbacks"></div>
                </div>
            `);
            
            const callbacksContainer = hookGroup.find('.wp-hook-profiler-hook-callbacks');
            
            callbacks.forEach(callback => {
                const timeMs = callback.total_time;
                const timeClass = getTimeColorClass(timeMs);
                
                const callbackItem = $(`
                    <div class="wp-hook-profiler-callback-item">
                        <div class="wp-hook-profiler-callback-info">
                            <div class="wp-hook-profiler-callback-name">${escapeHtml(callback.callback)}</div>
                            <div class="wp-hook-profiler-callback-meta">
                                Plugin: ${escapeHtml(callback.plugin_name || callback.plugin)} | Priority: ${callback.priority}
                            </div>
                        </div>
                        <div class="wp-hook-profiler-callback-time ${timeClass}">
                            ${isNaN(timeMs) ? '0.000' : timeMs.toFixed(3)}ms
                        </div>
                    </div>
                `);
                
                callbacksContainer.append(callbackItem);
            });
            
            container.append(hookGroup);
        });
    }
    
    function populatePluginFilter() {
        if (!profileData?.plugins) return;

        const select = $('#wp-hook-profiler-filter-plugin');
        const currentValue = select.val();

        select.find('option:not(:first)').remove();

        Object.values(profileData.plugins).forEach(plugin => {
            select.append(`<option value="${escapeHtml(plugin.plugin_name)}">${escapeHtml(plugin.plugin_name)}</option>`);
        });

        select.val(currentValue);
    }
    
    function switchTab(tabName) {
        $('.wp-hook-profiler-tab').removeClass('active');
        $(`.wp-hook-profiler-tab[data-tab="${tabName}"]`).addClass('active');
        
        $('.wp-hook-profiler-tab-content').hide();
        $(`#wp-hook-profiler-tab-${tabName}`).show();
    }
    
    function switchToHooksTabWithPlugin(pluginName) {
        switchTab('hooks');
        $('#wp-hook-profiler-filter-plugin').val(pluginName);
        filterHooksList();
    }
    
    function sortTable(column, table) {
        const tbody = table.find('tbody');
        const rows = tbody.find('tr').toArray();
        
        let direction = 'asc';
        if (currentSort.column === column && currentSort.direction === 'asc') {
            direction = 'desc';
        }
        
        table.find('th').removeClass('sort-asc sort-desc');
        table.find(`th[data-sort="${column}"]`).addClass(`sort-${direction}`);
        
        currentSort = { column, direction };
        
        const columnIndex = table.find(`th[data-sort="${column}"]`).index();
        const isNumeric = table.find(`th[data-sort="${column}"]`).hasClass('numeric');
        
        rows.sort((a, b) => {
            let aVal = $(a).find(`td:eq(${columnIndex})`).text().trim();
            let bVal = $(b).find(`td:eq(${columnIndex})`).text().trim();
            
            if (isNumeric) {
                aVal = parseFloat(aVal) || 0;
                bVal = parseFloat(bVal) || 0;
            }
            
            if (direction === 'asc') {
                return aVal > bVal ? 1 : aVal < bVal ? -1 : 0;
            } else {
                return aVal < bVal ? 1 : aVal > bVal ? -1 : 0;
            }
        });
        
        tbody.empty().append(rows);
    }
    
    function filterPluginsTable() {
        const searchTerm = $('#wp-hook-profiler-search-plugins').val().toLowerCase();
        const tbody = $('#wp-hook-profiler-plugins-table');
        
        tbody.find('tr').each(function() {
            const pluginName = $(this).find('td:first').text().toLowerCase();
            $(this).toggle(pluginName.includes(searchTerm));
        });
    }
    
    function filterCallbacksTable() {
        const searchTerm = $('#wp-hook-profiler-search-callbacks').val().toLowerCase();
        const tbody = $('#wp-hook-profiler-callbacks-table');
        
        tbody.find('tr').each(function() {
            const text = $(this).text().toLowerCase();
            $(this).toggle(text.includes(searchTerm));
        });
    }
    
    function filterHooksList() {
        const searchTerm = $('#wp-hook-profiler-search-hooks').val().toLowerCase();
        const pluginFilter = $('#wp-hook-profiler-filter-plugin').val();

        // console.log('Plugin Filter:', pluginFilter, 'Search Term:', searchTerm);

        // If only search term and no plugin filter, use simpler logic
        if (!pluginFilter && searchTerm) {
            return filterHooksBySearch(searchTerm);
        }

        $('.wp-hook-profiler-hook-group').each(function() {
            const hookName = $(this).data('hook').toLowerCase();
            const matchesSearch = !searchTerm || hookName.includes(searchTerm);

            let hookHasMatchingCallbacks = !pluginFilter;
            let visibleCallbackCount = 0;

            if (pluginFilter) {
                // Filter individual callbacks within this hook group
                const callbackItems = $(this).find('.wp-hook-profiler-callback-item');
                callbackItems.each(function() {
                    const callbackMeta = $(this).find('.wp-hook-profiler-callback-meta');
                    const metaText = callbackMeta.text();

                    // Check if this callback matches the plugin filter
                    const matchesPlugin = metaText.includes(`Plugin: ${pluginFilter}`) ||
                                        metaText.toLowerCase().includes(`plugin: ${pluginFilter.toLowerCase()}`) ||
                                        metaText.toLowerCase().includes(pluginFilter.toLowerCase());

                    if (matchesPlugin) {
                        hookHasMatchingCallbacks = true;
                        visibleCallbackCount++;
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
            } else {
                // No plugin filter - show all callbacks
                $(this).find('.wp-hook-profiler-callback-item').show();
                hookHasMatchingCallbacks = true;
                visibleCallbackCount = $(this).find('.wp-hook-profiler-callback-item').length;
            }

            // Show/hide the entire hook group based on whether it has matching callbacks
            const shouldShowHook = matchesSearch && hookHasMatchingCallbacks && visibleCallbackCount > 0;
            $(this).toggle(shouldShowHook);

            // Update hook header to reflect filtered callback count
            if (shouldShowHook && pluginFilter) {
                const totalCallbacks = $(this).find('.wp-hook-profiler-callback-item').length;
                const hookHeader = $(this).find('.wp-hook-profiler-hook-header');
                const originalText = hookHeader.text().replace(/\(\d+\/\d+\)/, '').trim();
                if (visibleCallbackCount !== totalCallbacks) {
                    hookHeader.html(`${originalText} <small>(${visibleCallbackCount}/${totalCallbacks} callbacks)</small>`);
                }
            }
        });

        // Summary of filtering results
        const visibleHooks = $('.wp-hook-profiler-hook-group:visible').length;
        const totalHooks = $('.wp-hook-profiler-hook-group').length;
        const visibleCallbacks = $('.wp-hook-profiler-callback-item:visible').length;
        const totalCallbacks = $('.wp-hook-profiler-callback-item').length;

        // console.log(`Filter Results: ${visibleHooks}/${totalHooks} hooks visible, ${visibleCallbacks}/${totalCallbacks} callbacks visible`);
    }

    function filterHooksBySearch(searchTerm) {
        $('.wp-hook-profiler-hook-group').each(function() {
            const hookName = $(this).data('hook').toLowerCase();
            const hookMatches = hookName.includes(searchTerm);

            // Also search within callback names and meta
            let callbackMatches = false;
            $(this).find('.wp-hook-profiler-callback-item').each(function() {
                const callbackName = $(this).find('.wp-hook-profiler-callback-name').text().toLowerCase();
                const callbackMeta = $(this).find('.wp-hook-profiler-callback-meta').text().toLowerCase();

                if (callbackName.includes(searchTerm) || callbackMeta.includes(searchTerm)) {
                    callbackMatches = true;
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });

            const shouldShow = hookMatches || callbackMatches;
            $(this).toggle(shouldShow);

            // If hook matches but no specific callbacks match, show all callbacks
            if (hookMatches && !callbackMatches) {
                $(this).find('.wp-hook-profiler-callback-item').show();
            }
        });

        const visibleHooks = $('.wp-hook-profiler-hook-group:visible').length;
        const totalHooks = $('.wp-hook-profiler-hook-group').length;
        // console.log(`Search Results: ${visibleHooks}/${totalHooks} hooks visible`);
    }
    
    function groupCallbacksByHook(callbacks) {
        const groups = {};
        
        callbacks.forEach(callback => {
            if (!groups[callback.hook]) {
                groups[callback.hook] = [];
            }
            groups[callback.hook].push(callback);
        });
        
        Object.keys(groups).forEach(hookName => {
            groups[hookName].sort((a, b) => (b.total_time || 0) - (a.total_time || 0));
        });
        
        return groups;
    }
    
    function getTimeColorClass(timeMs) {
        if (timeMs > 10) return 'wp-hook-profiler-time-high';
        if (timeMs > 1) return 'wp-hook-profiler-time-medium';
        return 'wp-hook-profiler-time-low';
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function updatePluginLoadingTable() {
        if (!profileData?.plugin_loading) return;
        
        const tbody = $('#wp-hook-profiler-plugin-loading-table');
        const loadingData = Object.entries(profileData.plugin_loading);
        
        tbody.empty();
        
        // Sort by duration descending
        loadingData.sort(([,a], [,b]) => b.duration - a.duration);
        
        let totalLoadingTime = 0;
        let sunriseTime = 0;
        let muPluginsTime = 0;
        let networkPluginsTime = 0;
        let pluginsTime = 0;
        
        loadingData.forEach(([file, data]) => {
            const durationMs = data.duration; // Duration is already in milliseconds
            const timeClass = getTimeColorClass(durationMs);
            totalLoadingTime += durationMs;
            
            // Aggregate by type
            switch (data.type) {
                case 'sunrise':
                    sunriseTime += durationMs;
                    break;
                case 'mu_plugin':
                    muPluginsTime += durationMs;
                    break;
                case 'network_plugin':
                    networkPluginsTime += durationMs;
                    break;
                case 'plugin':
                    pluginsTime += durationMs;
                    break;
            }
            
            const row = $(`
                <tr data-type="${data.type}">
                    <td><span class="wp-hook-profiler-file-name" title="${escapeHtml(file)}">${escapeHtml(file.split('/').pop())}</span></td>
                    <td><span class="wp-hook-profiler-type-badge wp-hook-profiler-type-${data.type}">${escapeHtml(data.type)}</span></td>
                    <td class="numeric ${timeClass}">${durationMs.toFixed(3)}</td>
                    <td class="numeric">${data.start_time ? (data.start_time / 1e9).toFixed(6) : '-'}</td>
                    <td class="numeric">${data.end_time ? (data.end_time / 1e9).toFixed(6) : '-'}</td>
                </tr>
            `);
            
            tbody.append(row);
        });
        
        // Update summary
        $('#wp-hook-profiler-sunrise-time').text(sunriseTime.toFixed(3));
        $('#wp-hook-profiler-mu-plugins-time').text(muPluginsTime.toFixed(3));
        $('#wp-hook-profiler-network-plugins-time').text(networkPluginsTime.toFixed(3));
        $('#wp-hook-profiler-plugins-time').text(pluginsTime.toFixed(3));
        $('#wp-hook-profiler-total-loading-time').text(totalLoadingTime.toFixed(3));
        
        if (loadingData.length === 0) {
            tbody.append('<tr><td colspan="5">No plugin loading data available</td></tr>');
        }
    }
    
    function filterPluginLoadingTable() {
        const searchTerm = $('#wp-hook-profiler-search-plugin-loading').val().toLowerCase();
        const typeFilter = $('#wp-hook-profiler-filter-loading-type').val();
        const tbody = $('#wp-hook-profiler-plugin-loading-table');
        
        tbody.find('tr').each(function() {
            const fileName = $(this).find('td:first').text().toLowerCase();
            const type = $(this).data('type');
            
            const matchesSearch = !searchTerm || fileName.includes(searchTerm);
            const matchesType = !typeFilter || type === typeFilter;
            
            $(this).toggle(matchesSearch && matchesType);
        });
    }
    
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
    
    init();
    
    return {
        toggle: toggle,
        show: show,
        hide: hide
    };
    
})(jQuery);