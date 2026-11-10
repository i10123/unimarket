// Мои покупки: карточки с файлами, контактом продавца и оценкой.

import { TYPES, api, button, downloadLink, el, formatDate, price, showStatus, startPage } from "./common.js";
import { COLORS } from "./cards.js";
import { openDialog } from "./forms.js";

await startPage("purchases");

const list = document.getElementById("list");
const status = document.getElementById("status");

function rateSeller(p) {
  // Переключатели идут от 5 к 1: CSS переворачивает их, и подсвечиваются звёзды до выбранной.
  const stars = el(
    "fieldset",
    { class: "field stars" },
    el("legend", {}, "Оценка продавца"),
    [5, 4, 3, 2, 1].flatMap((n) => [
      el("input", { type: "radio", name: "rating", value: n, id: `star-${n}`, "aria-label": `${n} из 5` }),
      el("label", { for: `star-${n}`, title: `${n} из 5` }, "★"),
    ])
  );
  openDialog({
    title: "Оценить продавца",
    fields: [stars],
    submit: "Отправить",
    onSubmit: (data) => {
      if (!data.get("rating")) {
        throw new Error("Поставь оценку от 1 до 5.");
      }
      return api("/api/deals", { action: "rate", purchase_id: p.id, rating: Number(data.get("rating")) }).then(load);
    },
  });
}

// Цвет карточки задаёт тип материала, как в каталоге.
function card(p) {
  return el(
    "article",
    { class: `purchase material-card--${COLORS[p.type]}` },
    el(
      "div",
      { class: "purchase__top" },
      el("p", { class: "material-card__meta" }, el("span", {}, TYPES[p.type]), " ", el("span", {}, `${p.course} курс`)),
      el("p", { class: "row__meta" }, formatDate(p.purchased_at))
    ),
    el("h3", {}, el("a", { href: `/pages/material.html?id=${p.material_id}` }, p.title)),
    el(
      "a",
      { class: "purchase__seller", href: `/pages/author.html?id=${p.author_id}` },
      el("span", { class: "avatar", "aria-hidden": "true" }, p.seller_name.slice(0, 1).toUpperCase()),
      p.seller_name
    ),
    el("div", { class: "purchase__box" }, p.files.map(downloadLink)),
    p.seller_contact && el("p", { class: "purchase__contact" }, `Если с файлом что-то не так, напиши продавцу: ${p.seller_contact}`),
    el(
      "div",
      { class: "purchase__bottom" },
      el("p", { class: "material-card__price" }, price(p.price, "Бесплатно")),
      p.rating ? el("p", { class: "row__meta" }, `Твоя оценка: ${"★".repeat(p.rating)}`) : button("Оценить продавца", () => rateSeller(p))
    )
  );
}

async function load() {
  try {
    const { items } = await api("/api/deals?list=purchases");
    list.replaceChildren(...items.map(card));
    showStatus(status, items.length ? "" : "Покупок пока нет. Загляни в каталог.", false);
  } catch (error) {
    showStatus(status, error.message);
  }
}

load();
