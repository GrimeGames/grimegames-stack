/**
 * GrimeGames Custom AJAX Search - JavaScript
 * Version: 1.0.0
 */

(function() {
    'use strict';
    
    // Configuration
    const CONFIG = {
        minChars: 2,           // Minimum characters before searching
        debounceDelay: 300,    // Delay in ms before API call
        maxResults: 8,         // Maximum results to show
        apiEndpoint: '/wp-json/gg/v1/search',
    };
    
    // DOM elements
    const searchInput = document.getElementById('ggSearchInput');
    const searchResults = document.getElementById('ggSearchResults');
    const searchLoading = document.getElementById('ggSearchLoading');
    const searchOverlay = document.getElementById('ggSearchOverlay');
    
    if (!searchInput || !searchResults) {
        console.warn('GrimeGames Search: Required elements not found');
        return;
    }
    
    // State
    let debounceTimer = null;
    let currentResults = [];
    let selectedIndex = -1;
    let isLoading = false;
    
    /**
     * Debounced search function
     */
    function handleInput(e) {
        const query = e.target.value.trim();
        
        // Clear timer
        clearTimeout(debounceTimer);
        
        // Hide results if query too short
        if (query.length < CONFIG.minChars) {
            hideResults();
            return;
        }
        
        // Show loading state
        showLoading();
        
        // Debounce API call
        debounceTimer = setTimeout(() => {
            performSearch(query);
        }, CONFIG.debounceDelay);
    }
    
    /**
     * Perform AJAX search
     */
    function performSearch(query) {
        isLoading = true;
        
        const url = `${CONFIG.apiEndpoint}?q=${encodeURIComponent(query)}&limit=${CONFIG.maxResults}`;
        
        fetch(url)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                isLoading = false;
                hideLoading();
                
                if (data.results && data.results.length > 0) {
                    currentResults = data.results;
                    displayResults(data.results);
                } else {
                    displayNoResults(query);
                }
            })
            .catch(error => {
                console.error('GrimeGames Search Error:', error);
                isLoading = false;
                hideLoading();
                displayError();
            });
    }
    
    /**
     * Display search results
     */
    function displayResults(results) {
        selectedIndex = -1;
        
        const html = results.map((result, index) => {
            const stockClass = result.stock_status === 'outofstock' ? 'out-of-stock' : '';
            const imageUrl = result.image || 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="60" height="60"%3E%3Crect width="60" height="60" fill="%232A2A2A"/%3E%3C/svg%3E';
            
            return `
                <a href="${result.url}" class="gg-search-result" data-index="${index}">
                    <img src="${imageUrl}" alt="${escapeHtml(result.title)}" class="gg-search-result-image" loading="lazy">
                    <div class="gg-search-result-info">
                        <div class="gg-search-result-title">${highlightQuery(result.title, searchInput.value)}</div>
                        <div class="gg-search-result-meta">
                            ${result.sku ? `<span class="gg-search-result-sku">${escapeHtml(result.sku)}</span>` : ''}
                            ${result.rarity ? `<span class="gg-search-result-rarity">${escapeHtml(result.rarity)}</span>` : ''}
                            <span class="gg-search-result-price">${result.price_html}</span>
                            <span class="gg-search-result-stock ${stockClass}">${escapeHtml(result.stock)}</span>
                        </div>
                    </div>
                </a>
            `;
        }).join('');
        
        searchResults.innerHTML = html;
        showResults();
    }
    
    /**
     * Display no results message
     */
    function displayNoResults(query) {
        searchResults.innerHTML = `
            <div class="gg-search-no-results">
                No cards found for "<strong>${escapeHtml(query)}</strong>"<br>
                <small>Try searching by card name, set code, or rarity</small>
            </div>
        `;
        showResults();
    }
    
    /**
     * Display error message
     */
    function displayError() {
        searchResults.innerHTML = `
            <div class="gg-search-no-results">
                <strong>Search temporarily unavailable</strong><br>
                <small>Please try again in a moment</small>
            </div>
        `;
        showResults();
    }
    
    /**
     * Show results dropdown
     */
    function showResults() {
        searchResults.classList.add('active');
        if (searchOverlay) {
            searchOverlay.classList.add('active');
        }
    }
    
    /**
     * Hide results dropdown
     */
    function hideResults() {
        searchResults.classList.remove('active');
        if (searchOverlay) {
            searchOverlay.classList.remove('active');
        }
        currentResults = [];
        selectedIndex = -1;
    }
    
    /**
     * Show loading indicator
     */
    function showLoading() {
        if (searchLoading) {
            searchLoading.classList.add('active');
        }
    }
    
    /**
     * Hide loading indicator
     */
    function hideLoading() {
        if (searchLoading) {
            searchLoading.classList.remove('active');
        }
    }
    
    /**
     * Keyboard navigation
     */
    function handleKeyboard(e) {
        const resultElements = searchResults.querySelectorAll('.gg-search-result');
        
        if (!resultElements.length) return;
        
        switch(e.key) {
            case 'ArrowDown':
                e.preventDefault();
                selectedIndex = Math.min(selectedIndex + 1, resultElements.length - 1);
                updateSelection(resultElements);
                break;
                
            case 'ArrowUp':
                e.preventDefault();
                selectedIndex = Math.max(selectedIndex - 1, -1);
                updateSelection(resultElements);
                break;
                
            case 'Enter':
                if (selectedIndex >= 0 && selectedIndex < resultElements.length) {
                    e.preventDefault();
                    resultElements[selectedIndex].click();
                }
                break;
                
            case 'Escape':
                hideResults();
                searchInput.blur();
                break;
        }
    }
    
    /**
     * Update visual selection
     */
    function updateSelection(elements) {
        elements.forEach((el, index) => {
            if (index === selectedIndex) {
                el.style.background = 'rgba(123, 0, 255, 0.1)';
                el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            } else {
                el.style.background = '';
            }
        });
    }
    
    /**
     * Highlight search query in results
     */
    function highlightQuery(text, query) {
        if (!query) return escapeHtml(text);
        
        const escapedText = escapeHtml(text);
        const escapedQuery = escapeHtml(query);
        const regex = new RegExp(`(${escapedQuery.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
        
        return escapedText.replace(regex, '<strong>$1</strong>');
    }
    
    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    /**
     * Click outside to close
     */
    function handleClickOutside(e) {
        if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
            hideResults();
        }
    }
    
    // Event listeners
    searchInput.addEventListener('input', handleInput);
    searchInput.addEventListener('keydown', handleKeyboard);
    searchInput.addEventListener('focus', function() {
        if (currentResults.length > 0) {
            showResults();
        }
    });
    
    document.addEventListener('click', handleClickOutside);
    
    if (searchOverlay) {
        searchOverlay.addEventListener('click', hideResults);
    }
    
    // Clear search on form submit (optional)
    const searchForm = searchInput.closest('form');
    if (searchForm) {
        searchForm.addEventListener('submit', function(e) {
            // Let the form submit naturally, but hide results
            hideResults();
        });
    }
    
    console.log('✅ GrimeGames AJAX Search initialized');
})();
