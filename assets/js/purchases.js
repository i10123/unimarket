// Мои покупки: скачивание, подтверждение, обращение о проблеме и оценка продавца.

import { TYPES, api, button, downloadLink, el, formatDate, points, price, showStatus, startPage } from "./common.js";
import { field, openDialog } from "./forms.js";

await startPage("purchases");

const list = document.getElementById("list");
const status = document.getElementById("status");

const STATUSES = {
  pending: ["Ожидает подтверждения", "ozhidanie"],
  problem: ["Обращение отправлено", "problema"],
  completed: ["Завершено", "gotovo"],
  refunded: ["Баллы возвращены", ""],
};

// Выполняет действие над покупкой и перерисовывает список.
const deal = (action, purchase, extra = {}) => api("/api/deals", { action, purchase_id: purchase.id, ...extra }).then(load);

function confirmPurchase(p) {
  openDialog({
    title: "Подтвердить покупку?",
    text: `Продавец получит ${points(p.price)}. Если с файлом что-то не так, сначала сообщи о проблеме.`,
    submit: "Подтвердить",
    onSubmit: () => deal("confirm", p),
  });
}

function reportProblem(p) {
  openDialog({
    title: "Проблема с файлом",
    text: "Продавцу дадут 24 часа, чтобы заменить файл или вернуть баллы. Сообщить можно один раз.",
    fields: [field("Что не так", el("textarea", { name: "text", minlength: 10, maxlength: 1000, required: true }))],
    submit: "Отправить",
    onSubmit: (data) => deal("problem", p, { text: data.get("text") }),
  });
}

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
      return deal("rate", p, { rating: Number(data.get("rating")) });
    },
  });
}

function actions(p) {
  if (p.status === "pending") {
    return [button("Подтвердить", () => confirmPurchase(p), "main"), button("Проблема с файлом", () => reportProblem(p))];
  }
  if (p.status === "completed") {
    return p.rating ? el("p", { class: "row__meta" }, `Твоя оценка: ${"★".repeat(p.rating)}`) : button("Оценить продавца", () => rateSeller(p));
  }
  return [];
}

function note(p) {
  return {
    pending: `Баллы в резерве. Автоподтверждение: ${formatDate(p.deadline_at)}. До этого можно сообщить о проблеме.`,
    problem: `Продавец заменит файл или вернёт баллы до ${formatDate(p.deadline_at)}. Твоё сообщение: ${p.problem}`,
    refunded: "Баллы вернулись на твой баланс.",
  }[p.status];
}

function row(p) {
  const [label, badge] = STATUSES[p.status];
  const comment = note(p);
  return el(
    "li",
    { class: `row${p.status === "pending" ? " row--vnimanie" : ""}` },
    el(
      "div",
      { class: "row__text" },
      el("a", { class: "row__title", href: `/pages/material.html?id=${p.material_id}` }, p.title),
      el(
        "p",
        { class: "row__meta" },
        `Куплено ${formatDate(p.purchased_at)} · ${TYPES[p.type]} · ${p.course} курс · ${price(p.price)} · Продавец: `,
        el("a", { class: "file-link", href: `/pages/author.html?id=${p.author_id}` }, p.seller_name)
      ),
      el("p", {}, el("span", { class: `badge badge--${badge}` }, label)),
      comment && el("p", { class: "row__meta" }, comment),
      p.files.map(downloadLink)
    ),
    el("div", { class: "row__actions" }, actions(p))
  );
}

async function load() {
  try {
    const { items } = await api("/api/deals?list=purchases");
    list.replaceChildren(...items.map(row));
    showStatus(status, items.length ? "" : "Покупок пока нет. Загляни в каталог.", false);
  } catch (error) {
    showStatus(status, error.message);
  }
}

load();
