// Мои продажи: публикации со счётчиком покупок и ответы на обращения покупателей.

import { TYPES, api, button, el, formatDate, plural, points, price, showStatus, startPage } from "./common.js";
import { field, openDialog } from "./forms.js";

await startPage("sales");

const status = document.getElementById("status");

const STATUSES = {
  pending: ["Ждёт подтверждения", "ozhidanie"],
  problem: ["Нужен ответ", "problema"],
  completed: ["Баллы получены", "gotovo"],
  refunded: ["Баллы возвращены", ""],
};

function replaceFile(order) {
  openDialog({
    title: "Заменить файл",
    text: "Замена одна. Загрузи исправленный файл до срока: покупка завершится, и ты получишь баллы.",
    fields: [field("Исправленный файл", el("input", { name: "file", type: "file", required: true }))],
    submit: "Заменить",
    onSubmit: (data) => {
      data.set("action", "replace");
      data.set("purchase_id", order.id);
      return api("/api/deals", data).then(load);
    },
  });
}

function refund(order) {
  openDialog({
    title: "Вернуть баллы?",
    text: `${points(order.price)} вернутся покупателю, покупка закроется без оплаты.`,
    submit: "Вернуть баллы",
    onSubmit: () => api("/api/deals", { action: "refund", purchase_id: order.id }).then(load),
  });
}

function materialRow(m) {
  return el(
    "li",
    { class: "row" },
    el(
      "div",
      { class: "row__text" },
      el("a", { class: "row__title", href: `/pages/material.html?id=${m.id}` }, m.title),
      el("p", { class: "row__meta" }, `${TYPES[m.type]} · ${m.course} курс · ${price(m.price)} · опубликовано ${formatDate(m.created_at)}`)
    ),
    el("p", { class: "row__sum" }, `${m.sales} ${plural(m.sales, ["покупка", "покупки", "покупок"])}`)
  );
}

function orderRow(o) {
  const [label, badge] = STATUSES[o.status];
  return el(
    "li",
    { class: `row${o.status === "problem" ? " row--vnimanie" : ""}` },
    el(
      "div",
      { class: "row__text" },
      el("p", { class: "row__title" }, o.title),
      el("p", { class: "row__meta" }, `Покупатель: ${o.buyer_name} · ${price(o.price)} · ${formatDate(o.purchased_at)}`),
      el("p", {}, el("span", { class: `badge badge--${badge}` }, label)),
      o.status === "problem" && el("p", { class: "row__meta" }, `Обращение: «${o.problem}». Ответить нужно до ${formatDate(o.deadline_at)}, иначе баллы вернутся покупателю.`)
    ),
    o.status === "problem" && el("div", { class: "row__actions" }, button("Заменить файл", () => replaceFile(o), "main"), button("Вернуть баллы", () => refund(o)))
  );
}

// Пустой список показывает пояснение вместо пустого места.
function fill(list, rows, emptyText) {
  list.replaceChildren(...(rows.length ? rows : [el("li", { class: "empty" }, emptyText)]));
}

async function load() {
  try {
    const { materials, orders } = await api("/api/deals?list=sales");
    fill(document.getElementById("materials"), materials.map(materialRow), "Ты пока ничего не публиковал.");
    fill(document.getElementById("orders"), orders.map(orderRow), "Покупок твоих материалов пока нет.");
    showStatus(status, "");
  } catch (error) {
    showStatus(status, error.message);
  }
}

load();
