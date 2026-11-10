// Карточки материалов и сетка с кнопкой «Показать ещё» для каталога, избранного и профиля автора.

import { TYPES, api, el, price, rating, showStatus } from "./common.js";

export const COLORS = { notes: "green", solutions: "blue", cheatsheet: "purple" };

// Сердце: переключает избранное; при ошибке возвращает прежнее состояние.
export function favoriteButton(item) {
  const button = el(
    "button",
    { class: "material-card__save", type: "button", "aria-label": "В избранное" },
    el("img", { width: 24, height: 24, alt: "" })
  );
  const draw = () => {
    button.setAttribute("aria-pressed", String(!!item.is_favorite));
    button.firstChild.src = `/assets/icons/heart-${item.is_favorite ? "filled" : "outline"}.svg`;
  };
  button.addEventListener("click", async () => {
    const was = item.is_favorite;
    button.disabled = true;
    item.is_favorite = was ? 0 : 1;
    draw();
    try {
      await api("/api/market", { action: "favorite", material_id: item.id, on: !!item.is_favorite });
    } catch {
      item.is_favorite = was;
      draw();
    } finally {
      button.disabled = false;
    }
  });
  draw();
  return button;
}

function renderCard(item) {
  return el(
    "article",
    { class: `material-card material-card--${COLORS[item.type]}` },
    el(
      "div",
      { class: "material-card__top" },
      el("p", { class: "material-card__meta" }, el("span", {}, TYPES[item.type]), " ", el("span", {}, `${item.course} курс`)),
      favoriteButton(item)
    ),
    el(
      "div",
      { class: "material-card__content" },
      el("h3", {}, el("a", { class: "material-card__link", href: `/pages/material.html?id=${item.id}` }, item.title)),
      el("p", {}, item.description)
    ),
    el(
      "div",
      { class: "material-card__bottom" },
      el(
        "p",
        { class: "material-card__author" },
        el("a", { href: `/pages/author.html?id=${item.author_id}` }, item.author_name),
        item.author_votes > 0 && ` · ${rating(item.author_rating, item.author_votes)}`
      ),
      el("p", { class: "material-card__price" }, price(item.price, "Бесплатно"))
    )
  );
}

// Сетка карточек на странице с #grid, #status и #more. reload(params) загружает первую порцию,
// «Показать ещё» добавляет следующие.
export function createList(emptyText) {
  const grid = document.getElementById("grid");
  const more = document.getElementById("more");
  const status = document.getElementById("status");
  let params = {};
  let loaded = 0;
  let lastRequest = 0;

  async function load(append) {
    const request = ++lastRequest;
    more.disabled = true;
    try {
      const data = await api(`/api/market?${new URLSearchParams({ ...params, offset: append ? loaded : 0 })}`);
      // Пришёл ответ на устаревший запрос: фильтры уже сменились.
      if (request !== lastRequest) {
        return;
      }
      if (!append) {
        grid.replaceChildren();
        loaded = 0;
      }
      grid.append(...data.items.map(renderCard));
      loaded += data.items.length;
      more.hidden = loaded >= data.total;
      showStatus(status, data.total ? "" : emptyText, false);
      return data;
    } catch (error) {
      showStatus(status, error.message);
    } finally {
      more.disabled = request !== lastRequest;
    }
  }

  more.addEventListener("click", () => load(true));
  return {
    reload(newParams = params) {
      params = newParams;
      return load(false);
    },
  };
}
