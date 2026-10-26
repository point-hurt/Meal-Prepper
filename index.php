<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Doradca posiłków</title>
  <link rel="stylesheet" href="static/css/style.css">
</head>
<body>

  <div id="loading-indicator" class="loading-bar"></div>

  <main class="app-shell">

    <section class="panel">
      <div class="panel-header">
        <h2 class="panel-title">Sprzęt</h2>
        <div class="panel-actions">
          <button type="button" class="btn-text" id="btn-select-all-eq">Zaznacz wszystko</button>
          <span class="sep">/</span>
          <button type="button" class="btn-text" id="btn-clear-eq">Wyczyść</button>
        </div>
      </div>
      <div id="equipment-grid" class="tiles-grid"></div>
    </section>

    <section class="panel">
      <div class="panel-header">
        <h2 class="panel-title">Składniki</h2>
        <div class="panel-actions">
          <button type="button" class="btn-text" id="btn-clear-pantry">Wyczyść spiżarnię</button>
        </div>
      </div>

      <div class="search-wrap">
        <input 
          type="text" 
          id="ingredient-search" 
          class="input-search" 
          placeholder="Filtruj składniki..." 
          autocomplete="off"
        >
        <button type="button" id="clear-search-btn" class="btn-clear" title="Wyczyść">×</button>
      </div>

      <div id="category-filters" class="category-filters"></div>
      <div id="ingredients-grid" class="tiles-grid"></div>
    </section>

    <section class="panel">
      <div class="panel-header">
        <h2 class="panel-title">Maksymalny czas</h2>
        <span class="time-badge" id="prep-time-display">25 min</span>
      </div>

      <div class="slider-block">
        <input 
          type="range" 
          id="prep-time-slider" 
          class="range-slider" 
          min="5" 
          max="120" 
          step="5" 
          value="25"
        >
        <div class="slider-controls">
          <div class="presets">
            <button type="button" class="preset-pill time-preset-btn" data-time="15">15 min</button>
            <button type="button" class="preset-pill time-preset-btn" data-time="25">25 min</button>
            <button type="button" class="preset-pill time-preset-btn" data-time="45">45 min</button>
            <button type="button" class="preset-pill time-preset-btn" data-time="60">60 min</button>
            <button type="button" class="preset-pill time-preset-btn" data-time="120">Bez limitu</button>
          </div>

          <label class="checkbox-label">
            <input type="checkbox" id="filter-time-checkbox" checked>
            <span>Ukryj powyżej limitu</span>
          </label>
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-header">
        <h2 class="panel-title">Rekomendacje</h2>
        <span class="count-badge" id="results-count-badge">0</span>
      </div>
      <div id="recipes-grid" class="recipes-list"></div>
    </section>

  </main>

  <div id="recipe-modal" class="modal-backdrop" role="dialog" aria-modal="true">
    <div class="modal-box">
      <button type="button" id="modal-close-btn" class="modal-close" aria-label="Zamknij">×</button>
      <div id="modal-recipe-details"></div>
    </div>
  </div>

  <script type="module" src="static/js/app.js"></script>
</body>
</html>
