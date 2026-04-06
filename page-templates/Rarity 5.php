<style>
  * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
  }
  
  body {
    background: #0a0a0a;
    color: #ffffff;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  }
  
  /* Header Section */
  .category-header {
    background: linear-gradient(135deg, #1A1A1A 0%, #0A0A0A 100%);
    padding: 30px 20px 20px;
    text-align: center;
    border-bottom: 2px solid #7B00FF;
    margin-bottom: 30px;
    position: relative;
  }
  
  .crown-container {
    text-align: center;
    margin-bottom: 5px;
  }
  
  .crown-container img {
    max-width: 80px !important;
    height: auto;
    filter: drop-shadow(0 0 15px rgba(123, 0, 255, 0.5));
    animation: crownAnimation 4s ease-in-out infinite;
    display: inline-block;
    transform: translateY(100px);
  }
  
  @keyframes crownAnimation {
    0% { transform: translateY(100px) rotate(0deg); filter: drop-shadow(0 0 15px rgba(123, 0, 255, 0.5)); }
    25% { transform: translateY(100px) rotate(360deg); filter: drop-shadow(0 0 15px rgba(123, 0, 255, 0.5)); }
    27% { transform: translateY(100px) rotate(360deg); filter: drop-shadow(0 0 30px rgba(255, 255, 255, 0.9)) brightness(1.8); }
    30% { transform: translateY(100px) rotate(360deg); filter: drop-shadow(0 0 15px rgba(123, 0, 255, 0.5)); }
    100% { transform: translateY(100px) rotate(360deg); filter: drop-shadow(0 0 15px rgba(123, 0, 255, 0.5)); }
  }
  
  .logo-text {
    font-size: 32px !important;
    font-weight: 900 !important;
    letter-spacing: 2px !important;
    text-transform: uppercase !important;
    color: #FFFFFF !important;
    margin: 0 !important;
    padding-top: 20px !important;
    z-index: 1 !important;
    position: relative !important;
  }
  
  .tagline {
    font-size: 18px;
    color: #A855F7;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 2px;
    margin: 5px 0 15px 0 !important;
  }
  
  .category-header h1 {
    font-size: 36px;
    font-weight: 700;
    color: #FFFFFF;
    margin: 0 0 10px 0;
  }
  
  .category-header p {
    font-size: 16px;
    color: #A0A0A0;
    margin-bottom: 20px;
  }
  
  /* Header Navigation Buttons */
  .header-buttons {
    display: flex;
    justify-content: center;
    gap: 15px;
    margin-top: 25px;
  }
  
  .nav-button {
    display: inline-block;
    padding: 12px 30px;
    background: #7B00FF;
    color: #FFFFFF;
    text-decoration: none;
    font-weight: 600;
    font-size: 14px;
    border-radius: 6px;
    border: 2px solid #7B00FF;
    transition: all 0.3s ease;
    text-transform: uppercase;
    letter-spacing: 1px;
  }
  
  .nav-button:hover {
    background: transparent;
    color: #7B00FF;
    transform: translateY(-2px);
  }
  
  /* Main Container */
  .burst-container {
    display: flex;
    max-width: 1600px;
    margin: 0 auto;
    padding: 0 20px 40px 20px;
    gap: 25px;
  }
  
  /* Filters Sidebar */
  .filters-sidebar {
    width: 240px;
    flex-shrink: 0;
    background: #1a1a1a;
    border: 1px solid #333;
    border-radius: 8px;
    padding: 20px;
    height: fit-content;
    position: sticky;
    top: 20px;
  }
  
  .filters-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #7B00FF;
  }
  
  .filters-header h3 {
    font-size: 18px;
    color: #fff;
    font-weight: 700;
  }
  
  .clear-all {
    background: transparent;
    color: #7B00FF;
    border: 1px solid #7B00FF;
    padding: 6px 12px;
    border-radius: 4px;
    font-size: 12px;
    cursor: pointer;
    transition: all 0.3s ease;
  }
  
  .clear-all:hover {
    background: #7B00FF;
    color: #fff;
  }
  
  .filter-section {
    margin-bottom: 25px;
  }
  
  .filter-section h4 {
    font-size: 14px;
    color: #fff;
    margin-bottom: 12px;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  
  .filter-section select {
    width: 100%;
    padding: 10px;
    background: #0a0a0a;
    border: 1px solid #333;
    border-radius: 6px;
    color: #fff;
    font-size: 14px;
    cursor: pointer;
  }
  
  .rarity-filters {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }
  
  .rarity-option {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    padding: 8px;
    border-radius: 4px;
    transition: background 0.2s ease;
  }
  
  .rarity-option:hover {
    background: #252525;
  }
  
  .rarity-option input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
    accent-color: #7B00FF;
  }
  
  .rarity-option label {
    color: #ccc;
    font-size: 14px;
    cursor: pointer;
    flex: 1;
  }
  
  .price-filters {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }
  
  .price-option {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    padding: 8px;
    border-radius: 4px;
    transition: background 0.2s ease;
  }
  
  .price-option:hover {
    background: #252525;
  }
  
  .price-option input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
    accent-color: #7B00FF;
  }
  
  .price-option label {
    color: #ccc;
    font-size: 14px;
    cursor: pointer;
    flex: 1;
  }
  
  /* Products Container */
  .products-main {
    flex: 1;
    min-width: 0;
  }
  
  .products-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #333;
  }
  
  .product-count {
    color: #A855F7;
    font-size: 14px;
    font-weight: 600;
  }
  
  /* Search Bar */
  .search-bar-container {
    position: relative;
    display: flex;
    align-items: center;
  }
  
  #product-search {
    padding: 10px 40px 10px 15px;
    background: #1a1a1a;
    border: 1px solid #333;
    border-radius: 6px;
    color: #fff;
    font-size: 14px;
    width: 300px;
    transition: all 0.3s ease;
  }
  
  #product-search:focus {
    outline: none;
    border-color: #7B00FF;
    box-shadow: 0 0 0 2px rgba(123, 0, 255, 0.2);
  }
  
  #product-search::placeholder {
    color: #666;
  }
  
  #clear-search {
    position: absolute;
    right: 10px;
    background: transparent;
    border: none;
    color: #999;
    font-size: 16px;
    cursor: pointer;
    padding: 5px;
    transition: color 0.2s ease;
  }
  
  #clear-search:hover {
    color: #fff;
  }
  
  /* WooCommerce Product Grid Override */
  .woocommerce ul.products {
    display: grid !important;
    grid-template-columns: repeat(4, 1fr) !important;
    gap: 16px !important;
    margin: 0 !important;
    padding: 0 !important;
    list-style: none !important;
  }
  
  .woocommerce ul.products li.product {
    background: #1a1a1a !important;
    border: 1px solid #333 !important;
    border-radius: 8px !important;
    padding: 10px !important;
    transition: all 0.3s ease !important;
    position: relative !important;
    margin: 0 !important;
    width: 100% !important;
  }
  
  /* Rarity-based hover effects */
  .woocommerce ul.products li.product[data-rarity*="Ultra"]:hover {
    border-color: #FFD700 !important;
    box-shadow: 0 4px 20px rgba(255, 215, 0, 0.3) !important;
    transform: translateY(-4px) !important;
  }
  
  .woocommerce ul.products li.product[data-rarity*="Super"]:hover {
    border-color: #00BFFF !important;
    box-shadow: 0 4px 20px rgba(0, 191, 255, 0.3) !important;
    transform: translateY(-4px) !important;
  }
  
  .woocommerce ul.products li.product[data-rarity*="Secret"]:hover {
    border-color: #E8E8E8 !important;
    box-shadow: 0 4px 20px rgba(232, 232, 232, 0.4) !important;
    transform: translateY(-4px) !important;
  }
  
  .woocommerce ul.products li.product[data-rarity*="Starlight"]:hover {
    border-color: #FF69B4 !important;
    box-shadow: 0 4px 20px rgba(255, 105, 180, 0.3) !important;
    transform: translateY(-4px) !important;
    animation: rainbow-glow 2s ease-in-out infinite;
  }
  
  .woocommerce ul.products li.product[data-rarity*="Ultimate"]:hover {
    border-color: #FF8C00 !important;
    box-shadow: 0 4px 20px rgba(255, 140, 0, 0.4) !important;
    transform: translateY(-4px) !important;
  }
  
  .woocommerce ul.products li.product[data-rarity*="Collector"]:hover {
    border-color: #00FFD1 !important;
    box-shadow: 0 4px 20px rgba(0, 255, 209, 0.35) !important;
    transform: translateY(-4px) !important;
  }
  
  .woocommerce ul.products li.product[data-rarity*="Overframe"]:hover {
    border-color: #BF00FF !important;
    box-shadow: 0 4px 20px rgba(191, 0, 255, 0.4) !important;
    transform: translateY(-4px) !important;
    animation: overframe-glow 1.5s ease-in-out infinite;
  }
  
  @keyframes overframe-glow {
    0%, 100% { box-shadow: 0 4px 25px rgba(191, 0, 255, 0.5); }
    50% { box-shadow: 0 4px 40px rgba(191, 0, 255, 0.9); }
  }
  
  @keyframes rainbow-glow {
    0%, 100% { box-shadow: 0 4px 20px rgba(255, 0, 0, 0.3); }
    16% { box-shadow: 0 4px 20px rgba(255, 165, 0, 0.3); }
    33% { box-shadow: 0 4px 20px rgba(255, 255, 0, 0.3); }
    50% { box-shadow: 0 4px 20px rgba(0, 255, 0, 0.3); }
    66% { box-shadow: 0 4px 20px rgba(0, 0, 255, 0.3); }
    83% { box-shadow: 0 4px 20px rgba(75, 0, 130, 0.3); }
  }
  
  .woocommerce ul.products li.product img {
    width: 100% !important;
    height: auto !important;
    border-radius: 6px !important;
    margin-bottom: 10px !important;
  }
  
  .woocommerce ul.products li.product .woocommerce-loop-product__title {
    font-size: 13px !important;
    font-weight: 600 !important;
    color: #FFFFFF !important;
    margin-bottom: 8px !important;
    line-height: 1.3 !important;
    height: 35px !important;
    overflow: hidden !important;
    display: -webkit-box !important;
    -webkit-line-clamp: 2 !important;
    -webkit-box-orient: vertical !important;
    text-shadow: 0 1px 2px rgba(0,0,0,0.3) !important;
  }
  
  .woocommerce ul.products li.product .price {
    font-size: 18px !important;
    font-weight: 700 !important;
    color: #7B00FF !important;
    margin-bottom: 10px !important;
  }
  
  .woocommerce ul.products li.product .button {
    background: #7B00FF !important;
    color: #fff !important;
    border: none !important;
    padding: 10px 16px !important;
    border-radius: 6px !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    transition: all 0.3s ease !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    width: 100% !important;
    text-align: center !important;
  }
  
  .woocommerce ul.products li.product .button:hover {
    background: #6300CC !important;
    transform: scale(1.02) !important;
  }
  
  .nearly-gone-badge {
    position: absolute;
    top: 10px;
    right: 10px;
    background: #FF4444;
    color: #fff;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    z-index: 10;
    animation: pulse 2s ease-in-out infinite;
  }
  
  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
  }
  
  @media (max-width: 1400px) {
    .woocommerce ul.products {
      grid-template-columns: repeat(3, 1fr) !important;
    }
  }
  
  @media (max-width: 1024px) {
    .woocommerce ul.products {
      grid-template-columns: repeat(2, 1fr) !important;
    }
    .burst-container {
      gap: 20px;
    }
  }
  
  @media (max-width: 768px) {
    .burst-container {
      flex-direction: column;
    }
    
    .filters-sidebar {
      display: none !important;
    }
    
    .woocommerce ul.products {
      grid-template-columns: repeat(2, 1fr) !important;
      gap: 12px !important;
      padding: 15px !important;
    }
    
    .products-main {
      width: 100%;
    }
    
    .products-header {
      flex-direction: column;
      align-items: flex-start;
      gap: 0px;
      padding: 15px;
      margin-bottom: 0;
    }
    
    .mobile-filter-btn {
      display: flex !important;
      justify-content: space-between;
      align-items: center;
      width: 100%;
      padding: 12px 15px;
      background: #1a1a1a;
      border: 1px solid #333;
      border-radius: 8px;
      color: #fff;
      font-size: 14px;
      cursor: pointer;
      transition: all 0.3s ease;
    }
    
    .mobile-filter-btn:active {
      background: #252525;
    }
    
    .filter-btn-left {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    
    .filter-btn-icon {
      font-size: 18px;
    }
    
    .filter-btn-text {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
    }
    
    .filter-btn-title {
      font-weight: 600;
      font-size: 14px;
    }
    
    .filter-btn-count {
      font-size: 12px;
      color: #888;
    }
    
    .search-bar-container {
      display: flex !important;
      width: 100%;
    }
    
    #product-search {
      width: 100%;
    }
    
    .product-count {
      display: none !important;
    }
  }
  
  .mobile-filter-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.7);
    z-index: 9999;
    animation: fadeIn 0.3s ease;
  }
  
  .mobile-filter-modal.active {
    display: block;
  }
  
  .mobile-filter-content {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: #0a0a0a;
    border-radius: 20px 20px 0 0;
    max-height: 85vh;
    overflow-y: auto;
    animation: slideUp 0.3s ease;
    box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.5);
  }
  
  @keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
  }
  
  @keyframes slideUp {
    from { transform: translateY(100%); }
    to { transform: translateY(0); }
  }
  
  .mobile-filter-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px;
    border-bottom: 1px solid #333;
    position: sticky;
    top: 0;
    background: #0a0a0a;
    z-index: 10;
  }
  
  .mobile-filter-title {
    font-size: 18px;
    font-weight: 700;
    color: #fff;
  }
  
  .mobile-filter-close {
    background: transparent;
    border: none;
    color: #fff;
    font-size: 28px;
    cursor: pointer;
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  
  .mobile-filter-body {
    padding: 20px;
  }
  
  .mobile-filter-section {
    border-bottom: 1px solid #333;
    padding: 15px 0;
  }
  
  .mobile-filter-section:last-child {
    border-bottom: none;
  }
  
  .filter-section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
    padding: 8px 0;
  }
  
  .filter-section-title {
    font-size: 16px;
    font-weight: 600;
    color: #fff;
  }
  
  .filter-section-arrow {
    font-size: 20px;
    color: #888;
    transition: transform 0.3s ease;
  }
  
  .filter-section-header.active .filter-section-arrow {
    transform: rotate(180deg);
  }
  
  .filter-section-content {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease;
  }
  
  .filter-section-content.active {
    max-height: 500px;
    padding-top: 12px;
  }
  
  .mobile-filter-option {
    display: flex;
    align-items: center;
    padding: 10px 0;
  }
  
  .mobile-filter-option input[type="checkbox"] {
    width: 20px;
    height: 20px;
    margin-right: 12px;
    accent-color: #7B00FF;
  }
  
  .mobile-filter-option label {
    font-size: 14px;
    color: #ddd;
    cursor: pointer;
  }
  
  .mobile-sort-select {
    width: 100%;
    padding: 12px;
    background: #1a1a1a;
    border: 1px solid #333;
    border-radius: 6px;
    color: #fff;
    font-size: 14px;
    margin-top: 12px;
  }
  
  .mobile-filter-footer {
    display: flex;
    gap: 12px;
    padding: 20px;
    border-top: 1px solid #333;
    position: sticky;
    bottom: 0;
    background: #0a0a0a;
  }
  
  .mobile-filter-footer button {
    flex: 1;
    padding: 14px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
  }
  
  .mobile-remove-all {
    background: transparent;
    border: 1px solid #333;
    color: #fff;
  }
  
  .mobile-remove-all:active {
    background: #1a1a1a;
  }
  
  .mobile-apply {
    background: #7B00FF;
    border: none;
    color: #fff;
  }
  
  .mobile-apply:active {
    background: #6a00dd;
  }
  
  .mobile-filter-btn {
    display: none;
  }
</style>

<!-- Header Section -->
<div class="category-header">
  <div class="crown-container">
    <img src="https://grimegames.com/wp-content/uploads/2025/11/Cracked-crown.png" alt="Crown">
  </div>
  <div class="logo-text">
    <img src="https://grimegames.com/wp-content/uploads/2026/01/ChatGPT-Image-Jan-22-2026-07_32_57-PM.png" alt="GrimeGames" style="max-width: 200px !important; margin: 0 !important;">
  </div>
  <h1>YU-GI-OH! RARITY COLLECTION 5</h1>
  <p>Pre-order the latest singles from the Rarity Collection 5 set</p>
  <div class="header-buttons">
    <a href="https://grimegames.com/" class="nav-button">Go Home</a>
    <a href="https://grimegames.com/singles/" class="nav-button">Go to Singles</a>
  </div>
</div>

<!-- Main Container -->
<div class="burst-container">
  <!-- Filters Sidebar -->
  <aside class="filters-sidebar">
    <div class="filters-header">
      <h3>Filters</h3>
      <button class="clear-all" onclick="clearAllFilters()">Clear All</button>
    </div>
    
    <!-- Sort By -->
    <div class="filter-section">
      <h4>Sort by:</h4>
      <select id="sort-select" onchange="applyFilters()">
        <option value="best-selling">Best Selling</option>
        <option value="price-low">Price: Low to High</option>
        <option value="price-high">Price: High to Low</option>
      </select>
    </div>
    
    <!-- Rarity Filter -->
    <div class="filter-section">
      <h4>Rarity 💎</h4>
      <div class="rarity-filters">
        <div class="rarity-option">
          <input type="checkbox" id="ultra" value="Ultra Rare" onchange="applyFilters()">
          <label for="ultra">Ultra Rare</label>
        </div>
        <div class="rarity-option">
          <input type="checkbox" id="super" value="Super Rare" onchange="applyFilters()">
          <label for="super">Super Rare</label>
        </div>
        <div class="rarity-option">
          <input type="checkbox" id="secret" value="Secret Rare" onchange="applyFilters()">
          <label for="secret">Secret Rare</label>
        </div>
        <div class="rarity-option">
          <input type="checkbox" id="starlight" value="Starlight Rare" onchange="applyFilters()">
          <label for="starlight">Starlight Rare</label>
        </div>
        <div class="rarity-option">
          <input type="checkbox" id="ultimate" value="Ultimate Rare" onchange="applyFilters()">
          <label for="ultimate">Ultimate Rare</label>
        </div>
        <div class="rarity-option">
          <input type="checkbox" id="collectors" value="Collectors Rare" onchange="applyFilters()">
          <label for="collectors">Collectors Rare</label>
        </div>
        <div class="rarity-option">
          <input type="checkbox" id="overframe" value="Overframe Rare" onchange="applyFilters()">
          <label for="overframe">Overframe Rare</label>
        </div>
      </div>
    </div>
    
    <!-- Price Filter -->
    <div class="filter-section">
      <h4>Price 💰</h4>
      <div class="price-filters">
        <div class="price-option">
          <input type="checkbox" id="under5" value="0-5" onchange="applyFilters()">
          <label for="under5">Under £5</label>
        </div>
        <div class="price-option">
          <input type="checkbox" id="5to10" value="5-10" onchange="applyFilters()">
          <label for="5to10">£5 - £10</label>
        </div>
        <div class="price-option">
          <input type="checkbox" id="10to20" value="10-20" onchange="applyFilters()">
          <label for="10to20">£10 - £20</label>
        </div>
        <div class="price-option">
          <input type="checkbox" id="over20" value="20-999999" onchange="applyFilters()">
          <label for="over20">Over £20</label>
        </div>
      </div>
    </div>
  </aside>
  
  <!-- Products Container -->
  <div class="products-main">
    <div class="products-header">
      <div class="product-count" id="product-count">Loading products...</div>
      <div class="search-bar-container">
        <input type="text" id="product-search" placeholder="🔍 Search products..." onkeyup="searchProducts()">
        <button id="clear-search" onclick="clearSearch()" style="display:none;">✕</button>
      </div>
    </div>
    
    <!-- Mobile Filter Button (visible only on mobile) -->
    <button class="mobile-filter-btn" onclick="openMobileFilters()">
      <div class="filter-btn-left">
        <span class="filter-btn-icon">☰</span>
        <div class="filter-btn-text">
          <span class="filter-btn-title">Filter and sort</span>
          <span class="filter-btn-count" id="mobile-product-count">Loading...</span>
        </div>
      </div>
      <span>→</span>
    </button>
    
    <!-- WooCommerce Products Shortcode -->
    [products limit="-1" category="rc05" columns="4" orderby="date" order="desc"]
  </div>
  
  <!-- Mobile Filter Modal -->
  <div class="mobile-filter-modal" id="mobileFilterModal" onclick="closeMobileFiltersIfBackdrop(event)">
    <div class="mobile-filter-content" onclick="event.stopPropagation()">
      <div class="mobile-filter-header">
        <span class="mobile-filter-title">Filter and sort</span>
        <button class="mobile-filter-close" onclick="closeMobileFilters()">×</button>
      </div>
      
      <div class="mobile-filter-body">
        <!-- Sort By Section -->
        <div class="mobile-filter-section">
          <div class="filter-section-header" onclick="toggleMobileSection(this)">
            <span class="filter-section-title">Sort by:</span>
            <span class="filter-section-arrow">→</span>
          </div>
          <div class="filter-section-content">
            <select class="mobile-sort-select" id="mobile-sort-select" onchange="syncFiltersToDesktop(); applyFilters();">
              <option value="best-selling">Best Selling</option>
              <option value="price-low">Price: Low to High</option>
              <option value="price-high">Price: High to Low</option>
            </select>
          </div>
        </div>
        
        <!-- Rarity Section -->
        <div class="mobile-filter-section">
          <div class="filter-section-header active" onclick="toggleMobileSection(this)">
            <span class="filter-section-title">Rarity 💎</span>
            <span class="filter-section-arrow">→</span>
          </div>
          <div class="filter-section-content active">
            <div class="mobile-filter-option">
              <input type="checkbox" id="mobile-ultra" value="Ultra Rare" onchange="syncFiltersToDesktop(); applyFilters();">
              <label for="mobile-ultra">Ultra Rare</label>
            </div>
            <div class="mobile-filter-option">
              <input type="checkbox" id="mobile-super" value="Super Rare" onchange="syncFiltersToDesktop(); applyFilters();">
              <label for="mobile-super">Super Rare</label>
            </div>
            <div class="mobile-filter-option">
              <input type="checkbox" id="mobile-secret" value="Secret Rare" onchange="syncFiltersToDesktop(); applyFilters();">
              <label for="mobile-secret">Secret Rare</label>
            </div>
            <div class="mobile-filter-option">
              <input type="checkbox" id="mobile-starlight" value="Starlight Rare" onchange="syncFiltersToDesktop(); applyFilters();">
              <label for="mobile-starlight">Starlight Rare</label>
            </div>
            <div class="mobile-filter-option">
              <input type="checkbox" id="mobile-ultimate" value="Ultimate Rare" onchange="syncFiltersToDesktop(); applyFilters();">
              <label for="mobile-ultimate">Ultimate Rare</label>
            </div>
            <div class="mobile-filter-option">
              <input type="checkbox" id="mobile-collectors" value="Collectors Rare" onchange="syncFiltersToDesktop(); applyFilters();">
              <label for="mobile-collectors">Collectors Rare</label>
            </div>
            <div class="mobile-filter-option">
              <input type="checkbox" id="mobile-overframe" value="Overframe Rare" onchange="syncFiltersToDesktop(); applyFilters();">
              <label for="mobile-overframe">Overframe Rare</label>
            </div>
          </div>
        </div>
        
        <!-- Price Section -->
        <div class="mobile-filter-section">
          <div class="filter-section-header" onclick="toggleMobileSection(this)">
            <span class="filter-section-title">Price 💷</span>
            <span class="filter-section-arrow">→</span>
          </div>
          <div class="filter-section-content">
            <div class="mobile-filter-option">
              <input type="checkbox" id="mobile-under5" value="0-5" onchange="syncFiltersToDesktop(); applyFilters();">
              <label for="mobile-under5">Under £5</label>
            </div>
            <div class="mobile-filter-option">
              <input type="checkbox" id="mobile-5to10" value="5-10" onchange="syncFiltersToDesktop(); applyFilters();">
              <label for="mobile-5to10">£5 to £10</label>
            </div>
            <div class="mobile-filter-option">
              <input type="checkbox" id="mobile-10to20" value="10-20" onchange="syncFiltersToDesktop(); applyFilters();">
              <label for="mobile-10to20">£10 to £20</label>
            </div>
            <div class="mobile-filter-option">
              <input type="checkbox" id="mobile-over20" value="20-999999" onchange="syncFiltersToDesktop(); applyFilters();">
              <label for="mobile-over20">Over £20</label>
            </div>
          </div>
        </div>
        
        <!-- Availability Section -->
        <div class="mobile-filter-section">
          <div class="filter-section-header" onclick="toggleMobileSection(this)">
            <span class="filter-section-title">Availability</span>
            <span class="filter-section-arrow">→</span>
          </div>
          <div class="filter-section-content">
            <div class="mobile-filter-option">
              <input type="checkbox" id="mobile-in-stock" value="in-stock" onchange="applyFilters()">
              <label for="mobile-in-stock">In stock only</label>
            </div>
          </div>
        </div>
      </div>
      
      <div class="mobile-filter-footer">
        <button class="mobile-remove-all" onclick="clearAllFiltersMobile()">Remove all</button>
        <button class="mobile-apply" onclick="closeMobileFilters()">Apply</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const products = document.querySelectorAll('.woocommerce ul.products li.product');
  
  products.forEach(product => {
    const title = product.querySelector('.woocommerce-loop-product__title');
    if (!title) return;
    
    const titleText = title.textContent.toLowerCase();
    
    // Lazy load images below the fold
    const img = product.querySelector('img');
    if (img && !img.getAttribute('loading')) {
      img.setAttribute('loading', 'lazy');
    }
    
    if (titleText.includes('overframe')) {
      product.setAttribute('data-rarity', 'Overframe Rare');
    } else if (titleText.includes('starlight')) {
      product.setAttribute('data-rarity', 'Starlight Rare');
    } else if (titleText.includes('collectors rare') || titleText.includes("collector's rare")) {
      product.setAttribute('data-rarity', 'Collectors Rare');
    } else if (titleText.includes('ultimate rare')) {
      product.setAttribute('data-rarity', 'Ultimate Rare');
    } else if (titleText.includes('secret rare') || titleText.match(/\bscr\b/)) {
      product.setAttribute('data-rarity', 'Secret Rare');
    } else if (titleText.includes('ultra rare') || titleText.match(/\bur\b/)) {
      product.setAttribute('data-rarity', 'Ultra Rare');
    } else if (titleText.includes('super rare') || titleText.match(/\bspr\b/)) {
      product.setAttribute('data-rarity', 'Super Rare');
    } else {
      product.setAttribute('data-rarity', 'Common');
    }
    
    const priceEl = product.querySelector('.price .amount, .price');
    if (priceEl) {
      const priceText = priceEl.textContent.replace(/[^0-9.]/g, '');
      const price = parseFloat(priceText);
      product.setAttribute('data-price', price);
    }
    
    const stockEl = product.querySelector('.stock');
    if (stockEl) {
      const stockText = stockEl.textContent;
      const stockMatch = stockText.match(/\d+/);
      if (stockMatch) {
        const stock = parseInt(stockMatch[0]);
        product.setAttribute('data-stock', stock);
        if (stock <= 15 && stock > 0) {
          const imageContainer = product.querySelector('a.woocommerce-LoopProduct-link');
          if (imageContainer && !product.querySelector('.nearly-gone-badge')) {
            const badge = document.createElement('span');
            badge.className = 'nearly-gone-badge';
            badge.textContent = 'Nearly Gone!';
            imageContainer.style.position = 'relative';
            imageContainer.appendChild(badge);
          }
        }
      }
    }
  });
  
  updateProductCount();
  if (typeof ggInitProgressiveRender === "function") ggInitProgressiveRender();
});

function applyFilters() {
  if (typeof ggRevealAll === "function") ggRevealAll();
  const products = document.querySelectorAll('.woocommerce ul.products li.product');
  const selectedRarities = [];
  document.querySelectorAll('.rarity-filters input[type="checkbox"]:checked').forEach(cb => {
    selectedRarities.push(cb.value);
  });
  const selectedPrices = [];
  document.querySelectorAll('.price-filters input[type="checkbox"]:checked').forEach(cb => {
    const range = cb.value.split('-');
    selectedPrices.push({ min: parseFloat(range[0]), max: parseFloat(range[1]) });
  });
  const sortOption = document.getElementById('sort-select').value;
  const productsArray = Array.from(products);
  if (selectedRarities.length === 0 && selectedPrices.length === 0) {
    productsArray.forEach(product => { product.style.display = 'block'; });
  } else {
    productsArray.forEach(product => {
      let showProduct = true;
      if (selectedRarities.length > 0) {
        const productRarity = product.getAttribute('data-rarity') || '';
        let matchesRarity = false;
        for (let selectedRarity of selectedRarities) {
          if (productRarity === selectedRarity) { matchesRarity = true; break; }
        }
        if (!matchesRarity) showProduct = false;
      }
      if (selectedPrices.length > 0) {
        const productPrice = parseFloat(product.getAttribute('data-price')) || 0;
        if (!selectedPrices.some(range => productPrice >= range.min && productPrice <= range.max)) showProduct = false;
      }
      product.style.display = showProduct ? 'block' : 'none';
    });
  }
  const visibleProducts = productsArray.filter(p => p.style.display !== 'none');
  const container = document.querySelector('.woocommerce ul.products');
  if (sortOption === 'price-low') {
    visibleProducts.sort((a, b) => (parseFloat(a.getAttribute('data-price')) || 0) - (parseFloat(b.getAttribute('data-price')) || 0));
  } else if (sortOption === 'price-high') {
    visibleProducts.sort((a, b) => (parseFloat(b.getAttribute('data-price')) || 0) - (parseFloat(a.getAttribute('data-price')) || 0));
  }
  visibleProducts.forEach(product => { container.appendChild(product); });
  updateProductCount();
}

function clearAllFilters() {
  document.querySelectorAll('.rarity-filters input[type="checkbox"]').forEach(cb => cb.checked = false);
  document.querySelectorAll('.price-filters input[type="checkbox"]').forEach(cb => cb.checked = false);
  document.getElementById('sort-select').value = 'best-selling';
  if (typeof ggResetProgressiveRender === "function") {
    ggResetProgressiveRender();
  } else {
    document.querySelectorAll('.woocommerce ul.products li.product').forEach(product => { product.style.display = 'block'; });
    updateProductCount();
  }
}

function updateProductCount() {
  const visibleProducts = document.querySelectorAll('.woocommerce ul.products li.product[style="display: block"], .woocommerce ul.products li.product:not([style*="display: none"])');
  const count = visibleProducts.length;
  document.getElementById('product-count').textContent = `${count} product${count !== 1 ? 's' : ''}`;
  const mobileCount = document.getElementById('mobile-product-count');
  if (mobileCount) mobileCount.textContent = `${count} product${count !== 1 ? 's' : ''}`;
}

function searchProducts() {
  const searchInput = document.getElementById('product-search');
  const searchTerm = searchInput.value.toLowerCase().trim();
  const clearBtn = document.getElementById('clear-search');
  clearBtn.style.display = searchTerm ? 'block' : 'none';
  const products = document.querySelectorAll('.woocommerce ul.products li.product');
  products.forEach(product => {
    const title = product.querySelector('.woocommerce-loop-product__title');
    if (!title) return;
    const titleText = title.textContent.toLowerCase();
    if (searchTerm === '' || titleText.includes(searchTerm)) {
      const currentDisplay = product.style.display;
      if (currentDisplay !== 'none' || searchTerm !== '') product.style.display = 'block';
    } else {
      product.style.display = 'none';
    }
  });
  updateProductCount();
}

function clearSearch() {
  document.getElementById('product-search').value = '';
  document.getElementById('clear-search').style.display = 'none';
  searchProducts();
}

function openMobileFilters() {
  document.getElementById('mobileFilterModal').classList.add('active');
  document.body.style.overflow = 'hidden';
  syncFiltersToMobile();
}

function closeMobileFilters() {
  document.getElementById('mobileFilterModal').classList.remove('active');
  document.body.style.overflow = '';
  syncFiltersToDesktop();
}

function closeMobileFiltersIfBackdrop(event) {
  if (event.target.id === 'mobileFilterModal') closeMobileFilters();
}

function toggleMobileSection(header) {
  const content = header.nextElementSibling;
  const isActive = header.classList.contains('active');
  if (isActive) { header.classList.remove('active'); content.classList.remove('active'); }
  else { header.classList.add('active'); content.classList.add('active'); }
}

function syncFiltersToMobile() {
  document.getElementById('mobile-ultra').checked = document.getElementById('ultra')?.checked || false;
  document.getElementById('mobile-super').checked = document.getElementById('super')?.checked || false;
  document.getElementById('mobile-secret').checked = document.getElementById('secret')?.checked || false;
  document.getElementById('mobile-starlight').checked = document.getElementById('starlight')?.checked || false;
  document.getElementById('mobile-ultimate').checked = document.getElementById('ultimate')?.checked || false;
  document.getElementById('mobile-collectors').checked = document.getElementById('collectors')?.checked || false;
  document.getElementById('mobile-overframe').checked = document.getElementById('overframe')?.checked || false;
  document.getElementById('mobile-under5').checked = document.getElementById('under5')?.checked || false;
  document.getElementById('mobile-5to10').checked = document.getElementById('5to10')?.checked || false;
  document.getElementById('mobile-10to20').checked = document.getElementById('10to20')?.checked || false;
  document.getElementById('mobile-over20').checked = document.getElementById('over20')?.checked || false;
  document.getElementById('mobile-sort-select').value = document.getElementById('sort-select')?.value || 'best-selling';
}

function syncFiltersToDesktop() {
  if (document.getElementById('ultra')) document.getElementById('ultra').checked = document.getElementById('mobile-ultra').checked;
  if (document.getElementById('super')) document.getElementById('super').checked = document.getElementById('mobile-super').checked;
  if (document.getElementById('secret')) document.getElementById('secret').checked = document.getElementById('mobile-secret').checked;
  if (document.getElementById('starlight')) document.getElementById('starlight').checked = document.getElementById('mobile-starlight').checked;
  if (document.getElementById('ultimate')) document.getElementById('ultimate').checked = document.getElementById('mobile-ultimate').checked;
  if (document.getElementById('collectors')) document.getElementById('collectors').checked = document.getElementById('mobile-collectors').checked;
  if (document.getElementById('overframe')) document.getElementById('overframe').checked = document.getElementById('mobile-overframe').checked;
  if (document.getElementById('under5')) document.getElementById('under5').checked = document.getElementById('mobile-under5').checked;
  if (document.getElementById('5to10')) document.getElementById('5to10').checked = document.getElementById('mobile-5to10').checked;
  if (document.getElementById('10to20')) document.getElementById('10to20').checked = document.getElementById('mobile-10to20').checked;
  if (document.getElementById('over20')) document.getElementById('over20').checked = document.getElementById('mobile-over20').checked;
  if (document.getElementById('sort-select')) document.getElementById('sort-select').value = document.getElementById('mobile-sort-select').value;
}

function clearAllFiltersMobile() {
  document.querySelectorAll('.mobile-filter-option input[type="checkbox"]').forEach(cb => cb.checked = false);
  document.getElementById('mobile-sort-select').value = 'best-selling';
  syncFiltersToDesktop();
  applyFilters();
}

/* ── PROGRESSIVE RENDERING ── */
(function() {
    var BATCH_SIZE = 48;
    var allProducts = [];
    var isFiltered = false;
    var sentinel = null;
    var observer = null;

    window.ggInitProgressiveRender = function() {
        allProducts = Array.from(document.querySelectorAll('.woocommerce ul.products li.product'));
        if (allProducts.length === 0) return;

        allProducts.forEach(function(p, i) {
            if (i >= BATCH_SIZE) {
                p.setAttribute('data-deferred', 'true');
                p.style.display = 'none';
            }
        });

        var container = document.querySelector('.woocommerce ul.products');
        if (!container) return;
        sentinel = document.createElement('div');
        sentinel.id = 'gg-sentinel';
        sentinel.style.cssText = 'width:100%;height:1px;grid-column:1/-1;';
        container.appendChild(sentinel);

        observer = new IntersectionObserver(function(entries) {
            if (entries[0].isIntersecting && !isFiltered) revealNextBatch();
        }, { rootMargin: '300px' });
        observer.observe(sentinel);

        updateProductCount();
    };

    function revealNextBatch() {
        var deferred = allProducts.filter(function(p) {
            return p.getAttribute('data-deferred') === 'true';
        });
        if (deferred.length === 0) { if (observer) observer.disconnect(); return; }
        deferred.slice(0, BATCH_SIZE).forEach(function(p) {
            p.style.display = 'block';
            p.removeAttribute('data-deferred');
        });
        updateProductCount();
    }

    window.ggRevealAll = function() {
        isFiltered = true;
        if (observer) observer.disconnect();
        allProducts.forEach(function(p) {
            p.removeAttribute('data-deferred');
            p.style.display = 'block';
        });
    };

    window.ggResetProgressiveRender = function() {
        isFiltered = false;
        allProducts = Array.from(document.querySelectorAll('.woocommerce ul.products li.product'));
        allProducts.forEach(function(p, i) {
            if (i >= BATCH_SIZE) {
                p.setAttribute('data-deferred', 'true');
                p.style.display = 'none';
            } else {
                p.removeAttribute('data-deferred');
                p.style.display = 'block';
            }
        });
        var container = document.querySelector('.woocommerce ul.products');
        if (container && sentinel) {
            container.appendChild(sentinel);
            observer = new IntersectionObserver(function(entries) {
                if (entries[0].isIntersecting && !isFiltered) revealNextBatch();
            }, { rootMargin: '300px' });
            observer.observe(sentinel);
        }
        updateProductCount();
    };
})();

</script>