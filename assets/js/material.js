// Страница материала: описание, файлы, покупка, избранное, ссылка на автора.

import { TYPES, api, downloadLink, el, fileSize, points, price, rating, showStatus, startPage } from "./common.js";
import { COLORS, favoriteButton } from "./cards.js";
import { openDialog } from "./forms.js";

const user = await startPage();
const content = document.getElementById("content");
const status = document.getElementById("status");

function buy(material) {
  openDialog({
    title: "Покупка материала",
    text: material.price
      ? `Будет зарезервировано ${points(material.price)}. Покупку можно подтвердить в «Мои покупки», иначе через 24 часа баллы перейдут автору.`
      : "Материал бесплатный, баллы не списываются.",
    submit: material.price ? "Купить" : "Получить",
    onSubmit: async () => {
      await api("/api/deals", { action: "buy", material_id: material.id });
      location.reload();
    },
  });
}

// Правый блок: что можно сделать с материалом именно этому пользователю.
function buyBlock(material, purchase, own) {
  const action = (() => {
    if (own) {
      return el("p", {}, "Это твой материал.");
    }
    if (purchase?.status === "refunded") {
      return el("p", {}, "Баллы за эту покупку возвращены. Повторно купить материал нельзя.");
    }
    if (purchase) {
      return el("p", {}, "Материал куплен. ", el("a", { class: "file-link", href: "/pages/purchases.html" }, "Мои покупки"));
    }
    const tooExpensive = material.price > user.available;
    return [
      el("button", { class: "button button--dark", type: "button", disabled: tooExpensive, onclick: () => buy(material) }, material.price ? "Купить материал" : "Получить бесплатно"),
      tooExpensive && el("p", { class: "form__status" }, "Не хватает баллов для покупки."),
    ];
  })();

  return el(
    "aside",
    { class: "panel", "aria-label": "Покупка" },
    el("h2", {}, "Покупка материала"),
    el("p", { class: "big-number" }, price(material.price, "Бесплатно")),
    action,
    el(
      "p",
      { class: "row__meta" },
      "Автор: ",
      el("a", { class: "file-link", href: `/pages/author.html?id=${material.author_id}` }, material.author_name),
      ` · ${rating(material.author_rating, material.author_votes)}`
    )
  );
}

function render({ material, files, purchase, own }) {
  const canDownload = own || (purchase && purchase.status !== "refunded");
  document.title = `${material.title} — УниМаркет`;

  content.replaceChildren(
    el("a", { class: "back", href: "/pages/catalog.html" }, "← В каталог"),
    el("h1", {}, material.title),
    el(
      "div",
      { class: "material" },
      el(
        "section",
        { class: `material__info material-card--${COLORS[material.type]}` },
        el("div", { class: "material__top" }, el("p", {}, `${TYPES[material.type]} · ${material.subject} · ${material.course} курс`), favoriteButton(material)),
        el("p", { class: "material__text" }, material.description),
        el(
          "div",
          { class: "material__files" },
          el("h2", {}, "Учебные файлы"),
          files.map((file) => (canDownload ? downloadLink(file) : el("p", { class: "row__meta" }, `${file.name} · ${fileSize(file.size)}`))),
          !canDownload && el("p", { class: "row__meta" }, "Файлы доступны сразу после покупки.")
        )
      ),
      buyBlock(material, purchase, own)
    )
  );
}

try {
  render(await api(`/api/market?id=${encodeURIComponent(new URLSearchParams(location.search).get("id"))}`));
} catch (error) {
  showStatus(status, error.message);
}
