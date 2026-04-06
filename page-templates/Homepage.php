<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GrimeGames - Premium Yu-Gi-Oh! Singles</title>

    <!-- Preload LCP image so the browser fetches it immediately -->
    <link rel="preload" as="image" href="https://grimegames.com/wp-content/uploads/2026/03/banner-rc5-bg.jpg" fetchpriority="high">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #0A0A0A;
            color: #E5E7EB;
            line-height: 1.6;
            padding-top: 200px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .sticky-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: rgba(10, 10, 10, 0.98);
            backdrop-filter: blur(10px);
            border-bottom: 2px solid #7B00FF;
            z-index: 1000;
        }


        .header-top {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1px 150px 0;
        }

        .header-layout {
            display: flex;
            align-items: center;
            gap: 40px; /* Desktop: original gap */
            justify-content: space-between;
        }

        .logo-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex-shrink: 0;
        }

        .crown-container {
            text-align: center;
            margin-bottom: -5px;
            position: relative;
            z-index: 2;
        }

        .crown-container img {
            max-width: 100px !important; /* Desktop: original size */
            height: auto;
            filter: drop-shadow(0 0 15px rgba(123, 0, 255, 0.5));
            animation: crownAnimation 4s ease-in-out infinite;
            display: inline-block;
        }

        @keyframes crownAnimation {
            0% { transform: rotate(0deg); filter: drop-shadow(0 0 15px rgba(123, 0, 255, 0.5)); }
            25% { transform: rotate(360deg); filter: drop-shadow(0 0 15px rgba(123, 0, 255, 0.5)); }
            27% { transform: rotate(360deg); filter: drop-shadow(0 0 30px rgba(255, 255, 255, 0.9)) brightness(1.8); }
            30% { transform: rotate(360deg); filter: drop-shadow(0 0 15px rgba(123, 0, 255, 0.5)); }
            100% { transform: rotate(360deg); filter: drop-shadow(0 0 15px rgba(123, 0, 255, 0.5)); }
        }

        .logo-container {
            position: relative;
            z-index: 1;
            margin-top: -120px; /* Desktop: original overlap */
        }

        .logo-container img {
            max-width: 250px; /* Desktop: original size */
            height: auto;
            display: block;
        }

        .tagline {
            display: none;
        }

        .search-wrapper {
            flex: 1;
            min-width: 0;
        }

        .cta-buttons-wrapper {
            display: flex;
            gap: 8px;
            margin-top: 6px;
            margin-bottom: 2px;
        }

        /* ── SEARCH STYLES ── */
        .gg-search-container {
            position: relative;
            width: 100%;
        }
        .gg-search-form {
            display: flex;
            background: #1A1A1A;
            border: 2px solid #2A2A2A;
            border-radius: 50px;
            overflow: hidden;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .gg-search-form:focus-within {
            border-color: #7B00FF;
            box-shadow: 0 0 0 3px rgba(123,0,255,0.1);
        }
        .gg-search-input {
            flex: 1;
            min-width: 0;
            background: transparent;
            border: none;
            padding: 12px 25px;
            color: #FFFFFF;
            font-size: 15px;
            outline: none;
        }
        .gg-search-input::placeholder { color: #6B7280; }
        .gg-search-btn {
            background: #7B00FF;
            border: none;
            color: #FFFFFF;
            padding: 12px 30px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            border-radius: 0 50px 50px 0;
            transition: background 0.3s ease;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .gg-search-btn:hover { background: #9333EA; }
        .gg-search-dropdown {
            position: absolute;
            top: calc(100% + 6px);
            left: 0; right: 0;
            background: #1A1A1A;
            border: 2px solid #7B00FF;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(123,0,255,0.3);
            z-index: 9999;
            display: none;
            overflow: hidden;
        }
        .gg-search-dropdown.active { display: block; }
        .gg-search-result {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 16px;
            border-bottom: 1px solid #2A2A2A;
            cursor: pointer;
            text-decoration: none;
            color: #E5E7EB;
            transition: background 0.15s ease;
        }
        .gg-search-result:last-child { border-bottom: none; }
        .gg-search-result:hover { background: rgba(123,0,255,0.12); }
        .gg-search-result img {
            width: 36px; height: 36px;
            object-fit: contain;
            border-radius: 4px;
            flex-shrink: 0;
            background: #0A0A0A;
        }
        .gg-search-result-info { flex: 1; min-width: 0; }
        .gg-search-result-name {
            font-size: 13px; font-weight: 600; color: #FFFFFF;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .gg-search-result-price { font-size: 13px; font-weight: 700; color: #A855F7; }
        .gg-search-no-results { padding: 14px 16px; color: #6B7280; font-size: 13px; text-align: center; }
        .gg-search-view-all {
            display: block; padding: 10px 16px; text-align: center;
            background: rgba(123,0,255,0.08); color: #A855F7;
            font-size: 13px; font-weight: 600; text-decoration: none;
            border-top: 1px solid #2A2A2A; transition: background 0.15s ease;
        }
        .gg-search-view-all:hover { background: rgba(123,0,255,0.2); }

        /* Mobile header overrides */
        @media (max-width: 768px) {
            body { padding-top: 210px; }
            .header-top { padding: 4px 12px 6px; }
            .header-layout { flex-direction: column; gap: 2px; }
            .logo-section {
                width: 100%;
                margin-bottom: -4px;
            }
            .search-wrapper { width: 100%; }
            .crown-container img { max-width: 50px !important; }
            .crown-container { margin-bottom: -2px; }
            .logo-container { margin-top: -58px; }
            .logo-container img { max-width: 140px; }
            .tagline { display: none; }
            .gg-search-input { padding: 10px 14px; font-size: 14px; }
            .gg-search-btn { padding: 10px 16px; font-size: 12px; }
            .cta-buttons-wrapper { flex-direction: row; gap: 6px; margin-top: 4px; }
            .cta-buttons-wrapper .btn { flex: 1; text-align: center; padding: 7px 8px; font-size: 12px; line-height: 1.2; }
        }

        .btn {
            padding: 8px 20px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s ease;
            display: inline-block;
            border: 2px solid;
        }

        .btn-primary {
            background: #7B00FF;
            color: #FFFFFF;
            border-color: #7B00FF;
        }

        .btn-primary:hover {
            background: transparent;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(123, 0, 255, 0.4);
        }

        .btn-secondary {
            background: transparent;
            color: #A855F7;
            border-color: #A855F7;
        }

        .btn-secondary:hover {
            background: #A855F7;
            color: #FFFFFF;
            transform: translateY(-2px);
        }

        .features {
            padding: 80px 0;
            background: #0A0A0A;
        }

        .carousel-container {
            position: relative;
            max-width: 100%;
            margin: 0 auto;
            overflow: hidden;
        }

        .carousel-wrapper {
            overflow: hidden;
            padding: 0 50px;
            cursor: grab;
        }

        .carousel-wrapper:active {
            cursor: grabbing;
        }

        .carousel-track {
            display: flex;
            gap: 20px;
            touch-action: pan-y pinch-zoom;
            user-select: none;
            -webkit-user-select: none;
        }

        .carousel-card {
            flex: 0 0 calc(25% - 15px);
            background: #1A1A1A;
            border-radius: 12px;
            overflow: hidden;
            border: 2px solid #2A2A2A;
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
        }

        .carousel-card:hover {
            border-color: #7B00FF;
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(123, 0, 255, 0.3);
        }

        .card-image {
            position: relative;
            padding-top: 140%;
            background: #0A0A0A;
            overflow: hidden;
        }

        .card-image img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .card-info {
            padding: 15px;
        }

        .card-info h3 {
            font-size: 13px;
            margin-bottom: 8px;
            color: #FFFFFF;
            overflow: visible;
            text-overflow: clip;
            white-space: normal;
            line-height: 1.3;
            min-height: 35px;
            text-align: center;
        }

        .card-price {
            font-size: 18px;
            font-weight: 700;
            color: #A855F7;
            text-align: center;
        }

        .carousel-btn {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: #7B00FF;
            color: #FFFFFF;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            font-size: 24px;
            cursor: pointer;
            z-index: 10;
            transition: all 0.3s ease;
        }

        .carousel-btn:hover {
            background: #A855F7;
            transform: translateY(-50%) scale(1.1);
        }

        .carousel-btn.prev { left: 0; }
        .carousel-btn.next { right: 0; }

        .carousel-dots {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 30px;
        }

        .dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #2A2A2A;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .dot.active {
            background: #7B00FF;
            transform: scale(1.2);
        }

        .section-header {
            text-align: center;
            margin-bottom: 50px;
        }

        .section-header h2 {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 15px;
            color: #FFFFFF;
        }

        .section-header p {
            color: #9CA3AF;
            font-size: 16px;
        }

        .cta-section {
            padding: 80px 0;
            background: #7B00FF;
            text-align: center;
        }

        .cta-section h2 {
            font-size: 42px;
            font-weight: 700;
            margin-bottom: 20px;
            color: #FFFFFF;
        }

        .cta-section p {
            font-size: 18px;
            margin-bottom: 30px;
            color: #E5E7EB;
        }

        /* ── BANNER STYLES ── */

        /* Kill any Search bar injected outside our custom header */
        .dgwt-wcas-search-wrapp,
        .dgwt-wcas-search-wrapp-mobile,
        .widget_dgwt_wcas_ajax_search,
        body > .dgwt-wcas-search-wrapp,
        .preorder-section .dgwt-wcas-search-wrapp,
        header ~ .dgwt-wcas-search-wrapp,
        header + .dgwt-wcas-search-wrapp {
            display: none !important;
            height: 0 !important;
            overflow: hidden !important;
            visibility: hidden !important;
        }

        .preorder-section { padding: 0; background:#000; border-bottom:2px solid #7B00FF; margin-top: 0; }

        /* ── BANNER 1: RC5 ── */
        .banner-rc5 {
            position:relative; width:100%; min-height:260px;
            background:linear-gradient(135deg,#0A0800 0%,#1A1200 30%,#0F0900 60%,#050300 100%);
            overflow:hidden; display:flex; align-items:center;
            border-top:1px solid rgba(212,175,55,0.3);
            border-bottom:1px solid rgba(212,175,55,0.3);
        }

        #sparklerCanvas {
            position:absolute; inset:0; width:100%; height:100%;
            pointer-events:none; z-index:9;
        }

        .rc5-bg {
            position:absolute; right:0; top:0; bottom:0; width:62%;
            background-image:url('https://grimegames.com/wp-content/uploads/2026/03/banner-rc5-bg.jpg');
            background-size:cover; background-position:center top; z-index:4;
            mask-image:linear-gradient(90deg,transparent 0%,rgba(0,0,0,0.1) 12%,rgba(0,0,0,0.65) 40%,black 100%);
            -webkit-mask-image:linear-gradient(90deg,transparent 0%,rgba(0,0,0,0.1) 12%,rgba(0,0,0,0.65) 40%,black 100%);
        }
        .rc5-bg-overlay {
            position:absolute; inset:0; z-index:5; pointer-events:none;
            background:linear-gradient(90deg,#0A0800 0%,#140E00 28%,rgba(10,8,0,0.5) 58%,rgba(0,0,0,0.15) 100%);
        }
        .banner-rc5-content {
            position:relative; z-index:10;
            display:flex; align-items:center;
            width:100%; padding:45px 80px; gap:40px;
        }
        .banner-rc5-text { flex:1; max-width:52%; }

        .banner-badge-rc5 {
            display:inline-block;
            background:linear-gradient(90deg,#B8860B,#FFD700,#B8860B);
            background-size:200% 100%; animation:goldShimmer 2s linear infinite;
            color:#000; font-size:11px; font-weight:900;
            letter-spacing:3px; text-transform:uppercase;
            padding:5px 16px; border-radius:20px; margin-bottom:18px;
        }
        @keyframes goldShimmer { 0% { background-position:200% 0; } 100% { background-position:-200% 0; } }

        .banner-rc5-title {
            font-size:clamp(28px,4vw,52px);
            font-weight:900; line-height:1.05; letter-spacing:-1px; margin-bottom:16px;
            color:#FFD700 !important;
            -webkit-text-fill-color:#FFD700 !important;
            text-shadow: 0 0 25px rgba(212,175,55,0.7), 0 2px 6px rgba(0,0,0,0.9);
        }

        .banner-rc5-tagline {
            font-size:clamp(15px,2vw,24px); font-weight:600;
            color:rgba(212,175,55,0.85); font-style:italic;
            margin-bottom:26px; line-height:1.4;
        }
        .banner-rc5-cta {
            display:inline-block;
            background:linear-gradient(135deg,#8B6914,#D4AF37,#8B6914);
            background-size:200% 100%; animation:goldShimmer 2s linear infinite;
            color:#000; font-size:14px; font-weight:800;
            letter-spacing:1px; text-transform:uppercase;
            padding:14px 36px; border-radius:6px; text-decoration:none;
            transition:all 0.3s ease; border:1px solid #FFD700; white-space:nowrap;
        }
        .banner-rc5-cta:hover { transform:scale(1.04); box-shadow:0 0 30px rgba(212,175,55,0.5),0 0 60px rgba(212,175,55,0.2); }

        /* ── BANNER 2: BLAZING DOMINION ── */
        .banner-bd {
            position:relative; width:100%; min-height:260px;
            background:#050000; overflow:hidden; display:flex; align-items:center;
        }
        .banner-bd-bg {
            position:absolute; right:0; top:0; bottom:0; width:65%;
            background-image:url('https://grimegames.com/wp-content/uploads/2026/03/banner-blazing-dominion-bg.jpg');
            background-size:cover; background-position:center left; z-index:1;
            mask-image:linear-gradient(90deg,transparent 0%,rgba(0,0,0,0.2) 18%,rgba(0,0,0,0.85) 50%,black 100%);
            -webkit-mask-image:linear-gradient(90deg,transparent 0%,rgba(0,0,0,0.2) 18%,rgba(0,0,0,0.85) 50%,black 100%);
        }
        .banner-bd-overlay {
            position:absolute; inset:0; z-index:2;
            background:linear-gradient(90deg,#050000 0%,#0D0000 38%,rgba(5,0,0,0.5) 62%,rgba(0,0,0,0.1) 100%);
        }
        .banner-bd::before {
            content:''; position:absolute; bottom:0; left:0; right:0; height:80px; z-index:3;
            background:radial-gradient(ellipse at 30% 100%,rgba(200,20,0,0.3) 0%,transparent 70%);
        }
        .banner-bd::after {
            content:''; position:absolute; bottom:0; left:0; right:0; height:3px; z-index:6;
            background:linear-gradient(90deg,transparent 0%,#CC0000 15%,#FF4500 30%,#FF6600 50%,#FF4500 70%,#CC0000 85%,transparent 100%);
            animation:fireLineFlicker 1.5s ease-in-out infinite alternate;
        }
        @keyframes fireLineFlicker { 0% { opacity:0.6; } 100% { opacity:1; box-shadow:0 0 15px #FF4500,0 0 30px #CC0000; } }

        .ember {
            position:absolute; border-radius:50%; pointer-events:none; z-index:5;
            animation:emberRise linear infinite; opacity:0;
        }
        @keyframes emberRise {
            0%   { transform:translateY(0) translateX(0) scale(1); opacity:0; }
            10%  { opacity:0.9; }
            80%  { opacity:0.5; }
            100% { transform:translateY(-200px) translateX(var(--drift)) scale(0.15); opacity:0; }
        }

        .banner-bd-content {
            position:relative; z-index:10;
            display:flex; align-items:center;
            width:100%; padding:45px 80px; gap:40px;
        }
        .banner-bd-text { flex:1; max-width:52%; }
        .banner-badge-bd {
            display:inline-block;
            background:linear-gradient(90deg,#8B0000,#CC0000,#8B0000);
            background-size:200%; animation:redShimmer 2.5s linear infinite;
            color:#FFE4B5; font-size:11px; font-weight:900;
            letter-spacing:3px; text-transform:uppercase;
            padding:5px 16px; border-radius:3px; margin-bottom:18px;
        }
        @keyframes redShimmer { 0% { background-position:0%; } 100% { background-position:200%; } }

        .banner-bd-title {
            font-size:clamp(26px,3.8vw,50px);
            font-weight:900; line-height:1.05; letter-spacing:-1px; margin-bottom:14px;
            color:#FF6600 !important;
            -webkit-text-fill-color:#FF6600 !important;
            text-shadow: 0 0 25px rgba(255,80,0,0.7), 0 2px 6px rgba(0,0,0,0.9);
        }

        .banner-bd-tags { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:22px; }
        .banner-bd-tag {
            background:rgba(180,0,0,0.25); border:1px solid rgba(255,70,0,0.4);
            color:#FF8866; font-size:11px; font-weight:700;
            letter-spacing:1px; text-transform:uppercase; padding:4px 12px; border-radius:3px;
        }
        .banner-bd-cta {
            display:inline-block;
            background:linear-gradient(135deg,#8B0000,#CC0000,#8B0000);
            background-size:200%; animation:redShimmer 2s linear infinite;
            color:#FFE4B5; font-size:14px; font-weight:800;
            letter-spacing:1px; text-transform:uppercase;
            padding:14px 36px; border-radius:4px; text-decoration:none;
            transition:all 0.3s ease; border:1px solid #FF4500; white-space:nowrap;
        }
        .banner-bd-cta:hover { transform:scale(1.04); box-shadow:0 0 30px rgba(255,80,0,0.5),0 0 60px rgba(200,0,0,0.3); }

        @media (max-width:768px) {
            .banner-rc5-content,.banner-bd-content { flex-direction:column; padding:30px 24px; text-align:center; }
            .banner-rc5-text,.banner-bd-text { max-width:100%; }
            .rc5-bg,.banner-bd-bg { width:100%; opacity:0.15; mask-image:none; -webkit-mask-image:none; }
            .banner-bd-tags { justify-content:center; }
            .banner-rc5-title { font-size:26px !important; letter-spacing:-0.5px; }
            .banner-bd-title { font-size:24px !important; letter-spacing:-0.5px; }
        }
    
        .banner-wrap { width:75%; margin:0 auto; overflow:hidden; }
        @media(max-width:768px){
            .banner-wrap{ width:100%; }
            .features .section-header h2 { font-size:22px !important; }
        }

        /* ── SALES TICKER ── */
        .gg-ticker-bar {
            width: 75%; margin: 0 auto;
            overflow: hidden;
            background: rgba(123, 0, 255, 0.08);
            border: 1px solid rgba(123, 0, 255, 0.25);
            border-radius: 6px;
            height: 32px;
            display: none;
            align-items: center;
        }
        .gg-ticker-bar.active { display: flex; }
        .gg-ticker-track {
            display: flex;
            white-space: nowrap;
            animation: tickerScroll 40s linear infinite;
            will-change: transform;
        }
        .gg-ticker-bar:hover .gg-ticker-track { animation-play-state: paused; }
        .gg-ticker-item {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 0 24px;
            font-size: 12px;
            font-weight: 600;
            color: #E5E7EB;
            white-space: nowrap;
        }
        .gg-ticker-dot { width:6px; height:6px; border-radius:50%; flex-shrink:0; }
        .gg-ticker-item.ebay    .gg-ticker-dot { background: #F5A623; }
        .gg-ticker-item.website .gg-ticker-dot { background: #7B00FF; }
        .gg-ticker-price { color: #A855F7; font-weight: 700; }
        .gg-ticker-sep { color: #374151; margin: 0 6px; }
        @keyframes tickerScroll {
            0%   { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }
        @media (max-width: 768px) {
            .gg-ticker-bar { width: 100%; border-radius: 0; border-left: none; border-right: none; margin: 0; }
        }

    </style>
</head>
<body>

    <header class="sticky-header" id="stickyHeader">
        <div class="header-top">
            <div class="header-layout">
                <div class="logo-section">
                    <div class="crown-container">
                        <img src="https://grimegames.com/wp-content/uploads/2025/11/Cracked-crown.png" alt="Crown">
                    </div>
                    <div class="logo-container">
                        <img src="https://grimegames.com/wp-content/uploads/2026/01/ChatGPT-Image-Jan-22-2026-07_32_57-PM.png" alt="GrimeGames">
                        <div class="tagline">#BanTheChaff</div>
                    </div>
                </div>
                <div class="search-wrapper">
                    <div class="gg-search-container">
                        <form action="/singles/" method="get" role="search" class="gg-search-form">
                            <input type="hidden" name="post_type" value="product">
                            <input
                                type="text"
                                id="ggSearchInput"
                                name="s"
                                class="gg-search-input"
                                placeholder="Search cards..."
                                autocomplete="off"
                                aria-label="Search products"
                            >
                            <button type="submit" class="gg-search-btn">SEARCH</button>
                        </form>
                        <div id="ggSearchResults" class="gg-search-dropdown" role="listbox"></div>
                    </div>
                    <div class="cta-buttons-wrapper">
                        <a href="/singles" class="btn btn-primary">Browse Our Singles</a>
                        <a href="mailto:matt@grimegames.com" class="btn btn-secondary">Contact Us</a>
                    </div>
                </div>
            </div>
        </div>
        <!-- Sales Ticker -->
        <div class="gg-ticker-bar" id="ggTickerBar">
            <div class="gg-ticker-track" id="ggTickerTrack"></div>
        </div>
    </header>
<section class="preorder-section">
    <div class="banner-wrap">
    <!-- BANNER 1: RARITY COLLECTION 5 -->
    <div class="banner-rc5">
        <canvas id="sparklerCanvas"></canvas>
        <div class="rc5-bg" id="rc5Bg"></div>
        <div class="rc5-bg-overlay"></div>
        <div class="banner-rc5-content">
            <div class="banner-rc5-text">
                <div class="banner-badge-rc5">Pre Sales Now Live! Click Below! </div>
                <h2 class="banner-rc5-title">Rarity Collection 5</h2>
                <p class="banner-rc5-tagline">Are you ready for overframe cards?</p>
                <a href="https://grimegames.com/rarity-collection-5/" class="banner-rc5-cta">Explore More →</a>
            </div>
        </div>
    </div>

    <!-- BANNER 2: BLAZING DOMINION -->
    <div class="banner-bd" id="blazingBanner">
        <div class="banner-bd-bg" id="bdBg"></div>
        <div class="banner-bd-overlay"></div>
        <div class="banner-bd-content">
            <div class="banner-bd-text">
                <div class="banner-badge-bd">Coming Soon — 7th May 2026</div>
                <h2 class="banner-bd-title">Blazing Dominion</h2>
                <div class="banner-bd-tags">
                    <span class="banner-bd-tag">Red Dragon Archfiend</span>
                    </div>
                <a href="https://grimegames.com/blazing-dominion/" class="banner-bd-cta">Explore More →</a>
            </div>
        </div>
    </div>
    </div><!-- /.banner-wrap -->
</section>


    <!-- Grime's Picks Section -->
    <section class="features">
        <div class="container">
            <div class="section-header">
                <h2>Grime's Picks of the Week</h2>
                <p>Most viewed cards over the past 7 days</p>
            </div>
            <div class="carousel-container">
                <button class="carousel-btn prev">&#8249;</button>
                <div class="carousel-wrapper">
                    <div class="carousel-track" id="carouselTrack">
                        [gg_most_viewed_carousel]
                    </div>
                </div>
                <button class="carousel-btn next">&#8250;</button>
            </div>
            <div class="carousel-dots" id="carouselDots"></div>
        </div>
    </section>

    <section class="cta-section">
        <div class="container">
           <h2 style="font-size: 1.5rem;">Ready to Build Your Deck?</h2>
            <p>Browse our complete catalogue of Yu-Gi-Oh! singles</p>
            <a href="/singles" class="btn" style="background: #FFFFFF; color: #7B00FF; border-color: #FFFFFF; font-size: 1=px;">
                Browse All Singles &#8594;
            </a>
            <p style="margin-top: 40px; font-size: 16px; font-style: italic; opacity: 0.8;">
                "One man's chaff is another man's treasure"
            </p>
        </div>
    </section>

    <script data-cfasync="false" src="/cdn-cgi/scripts/5c5dd728/cloudflare-static/email-decode.min.js"></script><script>

        /* ── CUSTOM AJAX SEARCH ── */
        (function() {
            'use strict';
            
            const CONFIG = {
                minChars: 2,
                debounceDelay: 300,
                maxResults: 8,
                apiEndpoint: '/wp-json/gg/v1/search',
            };
            
            const searchInput = document.getElementById('ggSearchInput');
            const searchResults = document.getElementById('ggSearchResults');
            
            if (!searchInput || !searchResults) return;
            
            let debounceTimer = null;
            let currentResults = [];
            let selectedIndex = -1;
            
            function handleInput(e) {
                const query = e.target.value.trim();
                clearTimeout(debounceTimer);
                if (query.length < CONFIG.minChars) { hideResults(); return; }
                debounceTimer = setTimeout(() => performSearch(query), CONFIG.debounceDelay);
            }
            
            function performSearch(query) {
                const url = `${CONFIG.apiEndpoint}?q=${encodeURIComponent(query)}&limit=${CONFIG.maxResults}`;
                fetch(url)
                    .then(r => r.ok ? r.json() : Promise.reject(`HTTP ${r.status}`))
                    .then(data => {
                        if (data.results && data.results.length > 0) {
                            currentResults = data.results;
                            displayResults(data.results);
                        } else {
                            displayNoResults(query);
                        }
                    })
                    .catch(error => {
                        console.error('Search Error:', error);
                        displayError();
                    });
            }
            
            function displayResults(results) {
                selectedIndex = -1;
                const html = results.map((r, i) => {
                    const img = r.image || 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="36" height="36"%3E%3Crect width="36" height="36" fill="%232A2A2A"/%3E%3C/svg%3E';
                    return `
                        <a href="${r.url}" class="gg-search-result" data-index="${i}">
                            <img src="${img}" alt="${esc(r.title)}" loading="lazy">
                            <div class="gg-search-result-info">
                                <div class="gg-search-result-name">${highlight(r.title, searchInput.value)}</div>
                                <div class="gg-search-result-price">${r.price_html}</div>
                            </div>
                        </a>
                    `;
                }).join('');
                searchResults.innerHTML = html;
                showResults();
            }
            
            function displayNoResults(query) {
                searchResults.innerHTML = `<div class="gg-search-no-results">No cards found for "<strong>${esc(query)}</strong>"</div>`;
                showResults();
            }
            
            function displayError() {
                searchResults.innerHTML = `<div class="gg-search-no-results"><strong>Search unavailable</strong></div>`;
                showResults();
            }
            
            function showResults() { searchResults.classList.add('active'); }
            function hideResults() {
                searchResults.classList.remove('active');
                currentResults = [];
                selectedIndex = -1;
            }
            
            function handleKeyboard(e) {
                const elements = searchResults.querySelectorAll('.gg-search-result');
                if (!elements.length) return;
                switch(e.key) {
                    case 'ArrowDown':
                        e.preventDefault();
                        selectedIndex = Math.min(selectedIndex + 1, elements.length - 1);
                        updateSelection(elements); break;
                    case 'ArrowUp':
                        e.preventDefault();
                        selectedIndex = Math.max(selectedIndex - 1, -1);
                        updateSelection(elements); break;
                    case 'Enter':
                        e.preventDefault();
                        if (selectedIndex >= 0) {
                            elements[selectedIndex].click();
                        } else if (currentResults.length > 0) {
                            window.location.href = currentResults[0].url;
                        }
                        break;
                    case 'Escape':
                        hideResults(); searchInput.blur(); break;
                }
            }
            
            function updateSelection(elements) {
                elements.forEach((el, i) => {
                    el.style.background = i === selectedIndex ? 'rgba(123, 0, 255, 0.1)' : '';
                    if (i === selectedIndex) el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                });
            }
            
            function highlight(text, query) {
                if (!query) return esc(text);
                const escaped = esc(text);
                const regex = new RegExp(`(${esc(query).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
                return escaped.replace(regex, '<strong>$1</strong>');
            }
            
            function esc(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
            
            searchInput.addEventListener('input', handleInput);
            searchInput.addEventListener('keydown', handleKeyboard);
            searchInput.addEventListener('focus', () => { if (currentResults.length > 0) showResults(); });
            document.addEventListener('click', e => {
                if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) hideResults();
            });
            const form = searchInput.closest('form');
            if (form) form.addEventListener('submit', function(e) { e.preventDefault(); });
            
            console.log('✅ GrimeGames Search initialized');
        })();


        // Grime's Picks Carousel
        let currentPosition = 0;
        let touchStartX = 0, touchStartY = 0, touchCurrentX = 0, isSwiping = false;
        let track = null, cards = [], totalCards = 0, initAttempts = 0, maxAttempts = 50;

        function initCarousel() {
            initAttempts++;
            track = document.getElementById('carouselTrack');
            if (!track) { if (initAttempts < maxAttempts) setTimeout(initCarousel, 100); return; }
            const links = track.querySelectorAll('a.carousel-card');
            if (links.length === 0) { if (initAttempts < maxAttempts) setTimeout(initCarousel, 100); return; }
            cards = links; totalCards = cards.length;
            createDots(); updateCarousel(); setupTouchEvents(); setupButtonEvents();
        }

        function setupButtonEvents() {
            const prevBtn = document.querySelector('.carousel-btn.prev');
            const nextBtn = document.querySelector('.carousel-btn.next');
            if (prevBtn) prevBtn.addEventListener('click', function(e) { e.preventDefault(); moveCarousel(-1); });
            if (nextBtn) nextBtn.addEventListener('click', function(e) { e.preventDefault(); moveCarousel(1); });
        }

        function updateCarousel() {
            if (!track || !cards.length) return;
            cards = track.querySelectorAll('a.carousel-card');
            totalCards = cards.length;
            if (cards.length === 0) return;
            const cardWidth = cards[0].offsetWidth;
            const gap = 20;
            const offset = currentPosition * (cardWidth + gap);
            track.style.transition = 'transform 0.3s ease';
            track.style.transform = `translateX(-${offset}px)`;
            updateDots();
        }

        function moveCarousel(direction) {
            if (!cards.length) return;
            const isMobile = window.innerWidth <= 768;
            const cardsPerView = isMobile ? 1 : 4;
            const maxPosition = Math.max(0, totalCards - cardsPerView);
            currentPosition += direction;
            if (currentPosition < 0) currentPosition = 0;
            else if (currentPosition > maxPosition) currentPosition = maxPosition;
            updateCarousel();
        }

        function setupTouchEvents() {
            const carouselWrapper = document.querySelector('.carousel-wrapper');
            if (!carouselWrapper || !track) return;
            carouselWrapper.addEventListener('touchstart', (e) => {
                touchStartX = e.touches[0].clientX; touchStartY = e.touches[0].clientY;
                touchCurrentX = touchStartX; isSwiping = false; track.style.transition = 'none';
            }, { passive: false });
            carouselWrapper.addEventListener('touchmove', (e) => {
                const touchX = e.touches[0].clientX; const touchY = e.touches[0].clientY;
                const deltaX = touchX - touchStartX; const deltaY = touchY - touchStartY;
                if (!isSwiping && Math.abs(deltaX) > 10 && Math.abs(deltaX) > Math.abs(deltaY)) isSwiping = true;
                if (isSwiping) {
                    e.preventDefault(); touchCurrentX = touchX;
                    const cardWidth = cards[0].offsetWidth;
                    const currentOffset = currentPosition * (cardWidth + 20);
                    track.style.transform = `translateX(-${currentOffset - deltaX}px)`;
                }
            }, { passive: false });
            carouselWrapper.addEventListener('touchend', (e) => {
                if (!isSwiping) { isSwiping = false; return; }
                const deltaX = touchCurrentX - touchStartX;
                isSwiping = false;
                if (Math.abs(deltaX) > 50) { if (deltaX > 0) moveCarousel(-1); else moveCarousel(1); }
                else {
                    const cardWidth = cards[0].offsetWidth;
                    const offset = currentPosition * (cardWidth + 20);
                    track.style.transition = 'transform 0.2s ease';
                    track.style.transform = `translateX(-${offset}px)`;
                }
            }, { passive: false });
        }

        function createDots() {
            const dotsContainer = document.getElementById('carouselDots');
            if (!dotsContainer) return;
            const isMobile = window.innerWidth <= 768;
            const cardsPerView = isMobile ? 1 : 4;
            const numDots = Math.max(1, totalCards - cardsPerView + 1);
            dotsContainer.innerHTML = '';
            for (let i = 0; i < numDots; i++) {
                const dot = document.createElement('div');
                dot.className = 'dot';
                dot.onclick = () => { currentPosition = i; updateCarousel(); };
                dotsContainer.appendChild(dot);
            }
        }

        function updateDots() {
            const dots = document.querySelectorAll('.dot');
            dots.forEach((dot, index) => dot.classList.toggle('active', index === currentPosition));
        }

        initCarousel();
        window.addEventListener('load', function() { setTimeout(initCarousel, 200); });
        window.addEventListener('resize', () => {
            currentPosition = 0;
            if (track) {
                cards = track.querySelectorAll('a.carousel-card');
                totalCards = cards.length;
                createDots();
                track.style.transition = 'none';
                track.style.transform = 'translateX(0px)';
                setTimeout(() => updateCarousel(), 100);
            }
        });

        /* ── BANNER SCRIPTS ── */

/* SPARKLERS */
(function() {
    const canvas = document.getElementById('sparklerCanvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    let particles = [];
    let running = true;

    function resize() {
        canvas.width  = canvas.parentElement.offsetWidth;
        canvas.height = canvas.parentElement.offsetHeight;
    }
    resize();
    window.addEventListener('resize', resize);

    function rand(a, b) { return a + Math.random() * (b - a); }

    function spawn() {
        return {
            x:      rand(0.01, 0.99) * canvas.width,
            y:      rand(0, 4),
            vx:     rand(-0.3, 0.3),
            vy:     rand(0.4, 1.5),
            life:   1.0,
            decay:  rand(0.003, 0.009),
            r:      rand(1.2, 4.0),
            bright: Math.random() > 0.4,
            trail:  []
        };
    }

    const isMobileDevice = window.innerWidth <= 768;
    const initParticles = isMobileDevice ? 30 : 80;
    const maxParticles  = isMobileDevice ? 50 : 100;
    for (let i = 0; i < initParticles; i++) {
        const p = spawn();
        p.life  = Math.random();
        p.y     = rand(0, canvas.height * 0.6);
        particles.push(p);
    }

    function goldColor(a) {
        const r = Math.floor(rand(220, 255));
        const g = Math.floor(rand(185, 230));
        const b = Math.floor(rand(0,   50));
        return `rgba(${r},${g},${b},${a})`;
    }

    (function draw() {
        if (running) {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            while (particles.length < maxParticles) particles.push(spawn());
            particles = particles.filter(p => p.life > 0 && p.y < canvas.height + 20);

            for (const p of particles) {
                p.trail.push({ x: p.x, y: p.y });
                if (p.trail.length > 8) p.trail.shift();

                for (let t = 0; t < p.trail.length - 1; t++) {
                    ctx.beginPath();
                    ctx.moveTo(p.trail[t].x,   p.trail[t].y);
                    ctx.lineTo(p.trail[t+1].x, p.trail[t+1].y);
                    ctx.strokeStyle = goldColor((t / p.trail.length) * p.life * 0.55);
                    ctx.lineWidth   = p.r * (t / p.trail.length);
                    ctx.stroke();
                }

                const g = ctx.createRadialGradient(p.x, p.y, 0, p.x, p.y, p.r * 3.0);
                g.addColorStop(0,   `rgba(255,255,220,${p.life * 0.97})`);
                g.addColorStop(0.2, `rgba(255,225,90,${p.life * 0.87})`);
                g.addColorStop(0.5, goldColor(p.life * 0.65));
                g.addColorStop(1,   'rgba(180,120,0,0)');
                ctx.beginPath();
                ctx.arc(p.x, p.y, p.r * 3.0, 0, Math.PI * 2);
                ctx.fillStyle = g;
                ctx.fill();

                if (p.bright && p.life > 0.35) {
                    const len = p.r * 8 * p.life;
                    ctx.strokeStyle = `rgba(255,245,170,${p.life * 0.62})`;
                    ctx.lineWidth = 0.9;
                    ctx.beginPath();
                    ctx.moveTo(p.x - len, p.y); ctx.lineTo(p.x + len, p.y);
                    ctx.moveTo(p.x, p.y - len); ctx.lineTo(p.x, p.y + len);
                    ctx.stroke();
                }

                p.x   += p.vx;
                p.y   += p.vy;
                p.vy  += 0.02;
                p.vx  += rand(-0.04, 0.04);
                p.life -= p.decay;
            }
        }
        requestAnimationFrame(draw);
    })();

    if ('IntersectionObserver' in window) {
        new IntersectionObserver(function(entries) {
            running = entries[0].isIntersecting;
        }, { threshold: 0 }).observe(canvas.parentElement);
    }
})();

/* EMBERS */
(function() {
    const banner = document.getElementById('blazingBanner');
    if (!banner) return;
    const colors = ['#FF6600','#FF4400','#FF2200','#FF8800','#FFAA00','#DD0000'];
    const embers = [];
    for (let i = 0; i < 30; i++) {
        const e  = document.createElement('div');
        e.className = 'ember';
        const sz = 2 + Math.random() * 5;
        const c  = colors[Math.floor(Math.random() * colors.length)];
        e.style.cssText = `width:${sz}px;height:${sz}px;left:${3+Math.random()*58}%;bottom:${Math.random()*30}px;background:radial-gradient(circle,#FFF 0%,${c} 50%,transparent 100%);animation-duration:${2+Math.random()*4}s;animation-delay:${Math.random()*6}s;--drift:${(Math.random()-0.5)*70}px;`;
        banner.appendChild(e);
        embers.push(e);
    }
    if ('IntersectionObserver' in window) {
        new IntersectionObserver(function(entries) {
            const state = entries[0].isIntersecting ? 'running' : 'paused';
            embers.forEach(function(e) { e.style.animationPlayState = state; });
        }, { threshold: 0 }).observe(banner);
    }
})();

/* ── SALES TICKER ── */
(function() {
    var bar   = document.getElementById('ggTickerBar');
    var track = document.getElementById('ggTickerTrack');
    if (!bar || !track) return;

    function buildTicker(data) {
        var sales = data && data.sales ? data.sales : [];
        if (!sales.length) return;
        var items = sales.concat(sales);
        track.innerHTML = items.map(function(s) {
            var cls  = s.source === 'ebay' ? 'ebay' : 'website';
            var icon = s.source === 'ebay' ? '🏆' : '✅';
            return '<span class="gg-ticker-item ' + cls + '">' +
                '<span class="gg-ticker-dot"></span>' +
                icon + ' ' + s.title +
                ' <span class="gg-ticker-price">£' + parseFloat(s.price).toFixed(2) + '</span>' +
                ' · ' + s.time_ago +
                '<span class="gg-ticker-sep">|</span>' +
                '</span>';
        }).join('');
        bar.classList.add('active');
    }

    function fetchSales() {
        fetch('/wp-json/gg/v1/sales-ticker?limit=5')
            .then(function(r) { return r.json(); })
            .then(buildTicker)
            .catch(function() {});
    }

    fetchSales();
    setInterval(fetchSales, 120000);
})();

/* Lazy-load carousel images */
(function() {
    function lazyCarousel() {
        document.querySelectorAll('#carouselTrack img').forEach(function(img) {
            if (!img.hasAttribute('loading')) img.setAttribute('loading', 'lazy');
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', lazyCarousel);
    } else {
        lazyCarousel();
    }
})();
</script>
</body>
</html>