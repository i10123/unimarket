// Мои материалы: публикации со счётчиком покупок и кнопкой редактирования, а также кто их купил.

import { TYPES, api, el, formatDate, plural, price, showStatus, startPage } from "./common.js";

await startPage("sales");

const status = document.getElementById("status");

const icon = (name) => el("img", { src: `/assets/icons/${name}.svg`, width: 20, height: 20, alt: "" });

function materialRow(m) {
  const archived = m.status === "archived";
  return el(
    "li",
    { class: "row" },
    el(
      "div",
      { class: "row__text" },
      el("a", { class: "row__title", href: `/pages/material.html?id=${m.id}` }, m.title),
      el("p", { class: "row__meta" }, `${TYPES[m.type]} · ${m.course} курс · ${price(m.price)} · опубликовано ${formatDate(m.created_at)}${archived ? " · снят с публикации" : ""}`)
    ),
    el(
      "div",
      { class: "row__actions" },
      el("p", { class: "row__sum" }, `${m.sales} ${plural(m.sales, ["покупка", "покупки", "покупок"])}`),
      el("a", { class: "button button--secondary button--ikonka", href: `/pages/publish.html?id=${m.id}`, title: "Редактировать", "aria-label": "Редактировать" }, icon("pencil")),
      el(
        "button",
        {
          class: "button button--secondary button--ikonka",
          type: "button",
          title: archived ? "Опубликовать снова" : "Снять с публикации",
          "aria-label": archived ? "Опубликовать снова" : "Снять с публикации",
          onclick: async () => {
            try {
              await api("/api/market", { action: "publish_state", id: m.id, on: archived });
              location.reload();
            } catch (error) {
              showStatus(status, error.message);
            }
          },
        },
        icon(archived ? "eye" : "eye-off")
      )
    )
  );
}

function orderRow(o) {
  return el(
    "li",
    { class: "row" },
    el(
      "div",
      { class: "row__text" },
      el("p", { class: "row__title" }, o.title),
      el("p", { class: "row__meta" }, `Покупатель: ${o.buyer_name} · ${price(o.price)} · ${formatDate(o.purchased_at)}`)
    )
  );
}

// Пустой список показывает пояснение вместо пустого места.
function fill(list, rows, emptyText) {
  list.replaceChildren(...(rows.length ? rows : [el("li", { class: "empty" }, emptyText)]));
}

try {
  const { materials, orders } = await api("/api/deals?list=sales");
  fill(document.getElementById("materials"), materials.map(materialRow), "Ты пока ничего не публиковал.");
  fill(document.getElementById("orders"), orders.map(orderRow), "Покупок твоих материалов пока нет.");
  showStatus(status, "");
} catch (error) {
  showStatus(status, error.message);
}
