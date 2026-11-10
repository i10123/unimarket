// Общее: профиль, счётчики и история баллов.

import { api, el, formatDate, rating, showStatus, startPage } from "./common.js";

const user = await startPage("account");
const content = document.getElementById("content");

// Подпись записи в истории; название материала приходит из БД.
function describe({ kind, title }) {
  const name = title ? ` «${title}»` : "";
  return {
    welcome: "Бонус за регистрацию",
    reward: `Награда за бесплатный материал${name}`,
    purchase: `Покупка${name}`,
    sale: `Продажа${name}`,
  }[kind];
}

function historyRow(entry) {
  const sum = entry.delta;
  return el(
    "li",
    { class: "row" },
    el(
      "div",
      { class: "row__text" },
      el("p", { class: "row__title" }, describe(entry)),
      el("p", { class: "row__meta" }, formatDate(entry.created_at))
    ),
    el("p", { class: `row__sum${sum > 0 ? " row__sum--plus" : ""}` }, `${sum > 0 ? "+" : "−"}${Math.abs(sum)}`)
  );
}

try {
  const { profile, history } = await api("/api/account");
  const name = [profile.first_name, profile.last_name].filter(Boolean).join(" ") || user.display_name;
  const counter = (value, title, href) => el("a", { class: "panel", href }, el("p", {}, title), el("strong", {}, value));

  content.append(
    el(
      "section",
      { class: "panel profile-head", "aria-label": "Профиль" },
      el("div", { class: "avatar", "aria-hidden": "true" }, name.slice(0, 1).toUpperCase()),
      el(
        "div",
        {},
        el("h2", {}, name),
        el("p", { class: "row__meta" }, [profile.course && `${profile.course} курс`, profile.votes > 0 && `рейтинг продавца ${rating(profile.rating, profile.votes)}`].filter(Boolean).join(" · ")),
        el("p", { class: "row__meta" }, profile.contact && `Контакты: ${profile.contact}`)
      ),
      el("a", { class: "button button--secondary", href: "/pages/settings.html" }, "Редактировать профиль")
    ),
    el(
      "div",
      { class: "counters" },
      counter(profile.purchases, "Мои покупки", "/pages/purchases.html"),
      counter(profile.materials, "Мои материалы", "/pages/sales.html")
    ),
    el("h2", {}, "История баллов"),
    el("ul", { class: "rows" }, history.map(historyRow))
  );
  document.getElementById("status").remove();
} catch (error) {
  showStatus(document.getElementById("status"), error.message);
}
