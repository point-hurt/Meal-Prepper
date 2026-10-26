"use strict";
/**
 * app.ts - Minimalistyczny interfejs doradcy posiłków
 */
class MealAdvisorApp {
    equipment = [];
    ingredients = [];
    recommendations = [];
    searchQuery = "";
    selectedCategory = "all";
    filterPantryOnly = false;
    filterKeyOnly = false;
    preferredPrepTime = 25;
    filterByTime = true;
    // DOM Elements
    equipmentGrid;
    ingredientsGrid;
    recipesGrid;
    searchInput;
    clearSearchBtn;
    prepTimeSlider;
    prepTimeDisplay;
    filterTimeCheckbox;
    recipeModal;
    modalContent;
    resultsCountBadge;
    categoryFiltersContainer;
    async init() {
        this.bindDOMElements();
        this.bindEvents();
        await this.loadInitialData();
    }
    bindDOMElements() {
        this.equipmentGrid = document.getElementById("equipment-grid");
        this.ingredientsGrid = document.getElementById("ingredients-grid");
        this.recipesGrid = document.getElementById("recipes-grid");
        this.searchInput = document.getElementById("ingredient-search");
        this.clearSearchBtn = document.getElementById("clear-search-btn");
        this.prepTimeSlider = document.getElementById("prep-time-slider");
        this.prepTimeDisplay = document.getElementById("prep-time-display");
        this.filterTimeCheckbox = document.getElementById("filter-time-checkbox");
        this.recipeModal = document.getElementById("recipe-modal");
        this.modalContent = document.getElementById("modal-recipe-details");
        this.resultsCountBadge = document.getElementById("results-count-badge");
        this.categoryFiltersContainer = document.getElementById("category-filters");
    }
    bindEvents() {
        this.searchInput.addEventListener("input", () => {
            this.searchQuery = this.searchInput.value.trim().toLowerCase();
            this.clearSearchBtn.style.display = this.searchQuery ? "inline-flex" : "none";
            this.renderIngredients();
        });
        this.clearSearchBtn.addEventListener("click", () => {
            this.searchInput.value = "";
            this.searchQuery = "";
            this.clearSearchBtn.style.display = "none";
            this.renderIngredients();
            this.searchInput.focus();
        });
        this.prepTimeSlider.addEventListener("input", () => {
            this.preferredPrepTime = parseInt(this.prepTimeSlider.value, 10);
            this.prepTimeDisplay.textContent = `${this.preferredPrepTime} min`;
        });
        this.prepTimeSlider.addEventListener("change", () => {
            this.preferredPrepTime = parseInt(this.prepTimeSlider.value, 10);
            this.savePreference("preferred_prep_time", this.preferredPrepTime.toString());
        });
        this.filterTimeCheckbox.addEventListener("change", () => {
            this.filterByTime = this.filterTimeCheckbox.checked;
            this.fetchRecommendations();
        });
        document.querySelectorAll(".time-preset-btn").forEach((btn) => {
            btn.addEventListener("click", (e) => {
                const val = e.currentTarget.getAttribute("data-time");
                if (val) {
                    const minutes = parseInt(val, 10);
                    this.preferredPrepTime = minutes;
                    this.prepTimeSlider.value = minutes.toString();
                    this.prepTimeDisplay.textContent = `${minutes} min`;
                    this.savePreference("preferred_prep_time", minutes.toString());
                }
            });
        });
        document.getElementById("btn-select-all-eq")?.addEventListener("click", () => this.toggleAllEquipment(true));
        document.getElementById("btn-clear-eq")?.addEventListener("click", () => this.toggleAllEquipment(false));
        document.getElementById("btn-clear-pantry")?.addEventListener("click", () => this.clearAllPantry());
        document.getElementById("modal-close-btn")?.addEventListener("click", () => this.closeModal());
        this.recipeModal.addEventListener("click", (e) => {
            if (e.target === this.recipeModal)
                this.closeModal();
        });
        window.addEventListener("keydown", (e) => {
            if (e.key === "Escape" && this.recipeModal.classList.contains("active")) {
                this.closeModal();
            }
        });
    }
    async loadInitialData() {
        try {
            this.setLoading(true);
            const res = await fetch("api.php?action=initial_data");
            if (!res.ok)
                throw new Error("Błąd ładowania danych");
            const data = await res.json();
            this.equipment = data.equipment;
            this.ingredients = data.ingredients;
            this.recommendations = data.recommendations.recipes;
            if (data.preferences && data.preferences.preferred_prep_time) {
                this.preferredPrepTime = parseInt(data.preferences.preferred_prep_time, 10);
                this.prepTimeSlider.value = this.preferredPrepTime.toString();
                this.prepTimeDisplay.textContent = `${this.preferredPrepTime} min`;
            }
            this.renderCategoryFilters();
            this.renderEquipment();
            this.renderIngredients();
            this.renderRecommendations();
        }
        catch (err) {
            console.error(err);
        }
        finally {
            this.setLoading(false);
        }
    }
    renderCategoryFilters() {
        const categories = Array.from(new Set(this.ingredients.map((i) => i.category)));
        let html = `
      <button class="cat-pill ${this.selectedCategory === "all" && !this.filterPantryOnly && !this.filterKeyOnly ? "active" : ""}" data-cat="all">
        Wszystkie
      </button>
      <button class="cat-pill ${this.filterPantryOnly ? "active" : ""}" data-special="pantry">
        W spiżarni
      </button>
      <button class="cat-pill ${this.filterKeyOnly ? "active" : ""}" data-special="key">
        Kluczowe
      </button>
    `;
        categories.forEach((cat) => {
            html += `
        <button class="cat-pill ${this.selectedCategory === cat ? "active" : ""}" data-cat="${cat}">
          ${cat}
        </button>
      `;
        });
        this.categoryFiltersContainer.innerHTML = html;
        this.categoryFiltersContainer.querySelectorAll(".cat-pill").forEach((pill) => {
            pill.addEventListener("click", (e) => {
                const btn = e.currentTarget;
                const cat = btn.getAttribute("data-cat");
                const special = btn.getAttribute("data-special");
                if (special === "pantry") {
                    this.filterPantryOnly = !this.filterPantryOnly;
                    this.filterKeyOnly = false;
                    this.selectedCategory = "all";
                }
                else if (special === "key") {
                    this.filterKeyOnly = !this.filterKeyOnly;
                    this.filterPantryOnly = false;
                    this.selectedCategory = "all";
                }
                else if (cat) {
                    this.selectedCategory = cat;
                    this.filterPantryOnly = false;
                    this.filterKeyOnly = false;
                }
                this.renderCategoryFilters();
                this.renderIngredients();
            });
        });
    }
    renderEquipment() {
        this.equipmentGrid.innerHTML = "";
        this.equipment.forEach((eq) => {
            const isOwned = eq.is_owned === 1;
            const tile = document.createElement("button");
            tile.type = "button";
            tile.className = `tile equipment-tile ${isOwned ? "is-owned" : "is-inactive"}`;
            tile.setAttribute("data-id", eq.id);
            tile.innerHTML = `
        <span class="tile-icon">${eq.icon || ""}</span>
        <span class="tile-title">${eq.name}</span>
      `;
            tile.addEventListener("click", () => this.toggleEquipment(eq));
            this.equipmentGrid.appendChild(tile);
        });
    }
    renderIngredients() {
        this.ingredientsGrid.innerHTML = "";
        let filtered = this.ingredients;
        if (this.selectedCategory !== "all") {
            filtered = filtered.filter((i) => i.category === this.selectedCategory);
        }
        if (this.filterPantryOnly) {
            filtered = filtered.filter((i) => i.is_owned === 1);
        }
        if (this.filterKeyOnly) {
            filtered = filtered.filter((i) => i.is_key === 1);
        }
        if (this.searchQuery) {
            filtered = filtered.filter((i) => i.name.toLowerCase().includes(this.searchQuery));
        }
        if (filtered.length === 0) {
            this.ingredientsGrid.innerHTML = `<div class="empty-state">Brak składników</div>`;
            return;
        }
        filtered.forEach((ing) => {
            const isOwned = ing.is_owned === 1;
            const isKey = ing.is_key === 1;
            const tile = document.createElement("div");
            tile.className = `tile ingredient-tile ${isOwned ? "is-owned" : "is-inactive"} ${isKey ? "is-key" : ""}`;
            tile.setAttribute("data-id", ing.id);
            tile.innerHTML = `
        <span class="tile-icon">${ing.icon || ""}</span>
        <span class="tile-title">${ing.name}</span>
        <div class="tile-actions">
          <button type="button" class="star-btn ${isKey ? "active" : ""}" title="Preferowany / Kluczowy">
            ★
          </button>
        </div>
      `;
            const starBtn = tile.querySelector(".star-btn");
            starBtn.addEventListener("click", (e) => {
                e.stopPropagation();
                this.toggleIngredientKey(ing);
            });
            tile.addEventListener("click", () => {
                this.toggleIngredientOwned(ing);
            });
            this.ingredientsGrid.appendChild(tile);
        });
    }
    formatEquipmentHtml(equipment) {
        return equipment.map((e) => {
            if (e.is_substitute) {
                return `<span class="tag tag-alt-eq">${e.name}${e.alt_time_min > 0 ? ` (+${e.alt_time_min} min)` : ''}</span>`;
            }
            return e.name;
        }).join(", ");
    }

    renderRecommendations() {
        this.recipesGrid.innerHTML = "";
        this.resultsCountBadge.textContent = `${this.recommendations.length}`;
        if (!this.recommendations.length) {
            this.recipesGrid.innerHTML = `<div class="empty-state">Brak dań spełniających kryteria sprzętu lub czasu.</div>`;
            return;
        }

        this.recommendations.forEach((rec) => {
            const card = document.createElement("article");
            card.className = "recipe-card";

            const missing = (rec.missing_ingredients || []).map(m => `<span class="tag tag-missing">${m.name}</span>`).join("");
            const keyIds = new Set((rec.key_ingredients || []).map(k => k.ingredient_id));
            const ownedList = [...(rec.owned_ingredients || [])].sort((a, b) => {
                const aKey = (a.is_key || keyIds.has(a.ingredient_id)) ? 1 : 0;
                const bKey = (b.is_key || keyIds.has(b.ingredient_id)) ? 1 : 0;
                return bKey - aKey;
            });
            const owned = ownedList.map(o => {
                const isKey = o.is_key || keyIds.has(o.ingredient_id);
                return isKey
                    ? `<span class="tag tag-key" title="Kluczowy składnik">★ ${o.name}</span>`
                    : `<span class="tag tag-owned">${o.name}</span>`;
            }).join("");
            const subs = (rec.substituted_ingredients || []).filter(s => !s.is_vegetarian)
                .map(s => `<span class="tag tag-sub">${s.original_name} → ${s.substitute_name}</span>`).join("");

            const timeDiff = rec.prep_time_min - (rec.base_prep_time_min || rec.prep_time_min);
            const timeText = timeDiff > 0 ? `${rec.prep_time_min} min (+${timeDiff} min)` : `${rec.prep_time_min} min`;

            card.innerHTML = `
              <div class="recipe-card-header">
                <div class="recipe-title-group">
                  <div class="recipe-title-row">
                    <h3 class="recipe-title">${rec.name}</h3>
                    ${rec.is_vegetarian_variant ? `<span class="tag tag-veg">Wersja wegetariańska</span>` : ""}
                  </div>
                  <span class="recipe-meta">${rec.category} · ${timeText} · ${rec.difficulty}</span>
                </div>
              </div>
              <p class="recipe-desc">${rec.description}</p>
              <div class="recipe-details-compact">
                ${(owned || subs) ? `<div class="card-row"><span class="tag-label">Posiadane:</span><div class="tags-wrap">${owned} ${subs}</div></div>` : ""}
                ${missing ? `<div class="card-row"><span class="tag-label">Brak:</span><div class="tags-wrap">${missing}</div></div>` : (!subs ? `<div class="card-row"><span class="tag tag-complete">Wszystkie składniki w spiżarni</span></div>` : "")}
                <div class="card-row"><span class="tag-label">Sprzęt:</span><span class="meta-plain">${this.formatEquipmentHtml(rec.equipment)}</span></div>
              </div>
              <div class="recipe-card-footer">
                <button type="button" class="btn btn-sm btn-view-recipe">Instrukcja</button>
              </div>
            `;
            card.querySelector(".btn-view-recipe")?.addEventListener("click", () => this.openModal(rec));
            this.recipesGrid.appendChild(card);
        });
    }
    async post(action, payload = {}) {
        try {
            const res = await fetch(`api.php?action=${action}`, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    preferred_prep_time: this.preferredPrepTime,
                    filter_by_prep_time: this.filterByTime,
                    ...payload,
                }),
            });
            const data = await res.json();
            if (data.success && data.recommendations) {
                this.recommendations = data.recommendations.recipes || data.recommendations;
                this.renderRecommendations();
            }
            return data;
        } catch (e) {
            console.error(e);
        }
    }
    async toggleEquipment(eq) {
        eq.is_owned = eq.is_owned === 1 ? 0 : 1;
        this.renderEquipment();
        await this.post("toggle_equipment", { equipment_id: eq.id, is_owned: eq.is_owned });
    }
    async toggleIngredientOwned(ing) {
        ing.is_owned = ing.is_owned === 1 ? 0 : 1;
        if (ing.is_owned === 0) ing.is_key = 0;
        this.renderIngredients();
        await this.post("toggle_ingredient", { ingredient_id: ing.id, is_owned: ing.is_owned, is_key: ing.is_key });
    }
    async toggleIngredientKey(ing) {
        ing.is_key = ing.is_key === 1 ? 0 : 1;
        if (ing.is_key === 1) ing.is_owned = 1;
        this.renderIngredients();
        await this.post("toggle_ingredient", { ingredient_id: ing.id, is_key: ing.is_key, is_owned: ing.is_owned });
    }
    async savePreference(key, val) {
        await this.post("set_preference", { key, value: val });
    }
    async fetchRecommendations() {
        await this.post("recommendations");
    }
    async toggleAllEquipment(owned) {
        const val = owned ? 1 : 0;
        this.equipment.forEach(e => e.is_owned = val);
        this.renderEquipment();
        await this.post("toggle_all_equipment", { is_owned: val });
    }
    async clearAllPantry() {
        this.ingredients.forEach(i => { i.is_owned = 0; i.is_key = 0; });
        this.renderIngredients();
        await this.post("clear_pantry");
    }
    openModal(rec) {
        const timeDiff = rec.prep_time_min - (rec.base_prep_time_min || rec.prep_time_min);
        const timeDetail = timeDiff > 0 ? `${rec.prep_time_min} min (${rec.base_prep_time_min} +${timeDiff} min)` : `${rec.prep_time_min} min`;

        const subs = rec.substituted_ingredients || [];
        const subsHtml = subs.length ? `
          <div class="modal-block modal-notice">
            <h4 class="modal-subtitle">Zastosowane reguły zamienników</h4>
            <ul class="sub-list">
              ${subs.map((s) => s.is_vegetarian
                ? `<li><strong>${s.original_name}:</strong> Pominięto składnik — wariant wegetariański.</li>`
                : `<li><strong>${s.original_name} → ${s.substitute_name}:</strong> Użyto zamiennika.</li>`
              ).join("")}
            </ul>
          </div>
        ` : "";

        const steps = rec.steps || rec.instruction_steps || [];
        const stepsHtml = steps.map((st) => `
          <li class="step-item ${st.is_substituted ? (st.is_vegetarian ? 'step-veg' : 'step-substituted') : ''}">
            <div class="step-line">
              <span class="step-text">${st.text}</span>
              ${st.is_substituted ? `
                <span class="tag ${st.is_vegetarian ? 'tag-veg' : 'tag-sub'} tag-step-badge">
                  ${st.substitute_label || (st.is_vegetarian ? 'Wersja wegetariańska' : 'Krok zastępczy')}
                </span>
              ` : ''}
            </div>
          </li>
        `).join("");

        this.modalContent.innerHTML = `
          <div class="modal-header">
            <div class="modal-title-row">
              <h2 class="modal-title">${rec.name}</h2>
              ${rec.is_vegetarian_variant ? `<span class="tag tag-veg">Wersja wegetariańska</span>` : ""}
            </div>
            <span class="modal-meta">${rec.category} · ${timeDetail} · ${rec.difficulty}</span>
          </div>

          <p class="modal-desc">${rec.description}</p>

          <div class="modal-block">
            <h4 class="modal-subtitle">Wymagany sprzęt</h4>
            <p class="meta-plain">${this.formatEquipmentHtml(rec.equipment)}</p>
          </div>

          ${subsHtml}

          <div class="modal-block">
            <h4 class="modal-subtitle">Instrukcja</h4>
            <ol class="steps-list">
              ${stepsHtml}
            </ol>
          </div>
        `;
        this.recipeModal.classList.add("active");
    }
    closeModal() {
        this.recipeModal.classList.remove("active");
    }
    setLoading(loading) {
        const spinner = document.getElementById("loading-indicator");
        if (spinner) {
            spinner.style.display = loading ? "block" : "none";
        }
    }
}
document.addEventListener("DOMContentLoaded", () => {
    const app = new MealAdvisorApp();
    app.init();
});
