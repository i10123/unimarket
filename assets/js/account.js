// Общее: профиль, баланс, счётчики и история баллов.

import { api, el, formatDate, points, rating, showStatus, startPage } from "./common.js";

const user = await startPage("account");
const content = document.getElementById("content");

// Подпись записи в истории; название материала приходит из БД.
function describe({ kind, available_delta: sum, title }) {
  const name = title ? ` «${title}»` : "";
  return {
    welcome: "Бонус за регистрацию",
    reward: `Награда за бесплатный материал${name}`,
    reserve: `Покупка${name}: баллы в резерве`,
    release: sum > 0 ? `Продажа${name}` : `Покупка${name} подтверждена`,
    refund: `Возврат баллов за покупку${name}`,
  }[kind];
}

function historyRow(entry) {
  const sum = entry.available_delta;
  return el(
    "li",
    { class: "row" },
    el(
      "div",
      { class: "row__text" },
      el("p", { class: "row__title" }, describe(entry)),
      el("p", { class: "row__meta" }, formatDate(entry.created_at) + (entry.reserved_delta ? ` · резерв ${entry.reserved_delta > 0 ? "+" : ""}${entry.reserved_delta}` : ""))
    ),
    sum !== 0 && el("p", { class: `row__sum${sum > 0 ? " row__sum--plus" : ""}` }, `${sum > 0 ? "+" : "−"}${Math.abs(sum)}`)
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
        el("p", { class: "row__meta" }, [profile.course && `${profile.course} курс`, `рейтинг продавца: ${rating(profile.rating, profile.votes)}`].filter(Boolean).join(" · "))
      ),
      el("a", { class: "button button--secondary", href: "/pages/settings.html" }, "Редактировать профиль")
    ),
    el(
      "section",
      { class: "panel panel--dark", "aria-label": "Баланс" },
      el("p", {}, "Твой баланс"),
      el("p", { class: "big-number" }, points(user.available)),
      el("p", { class: "row__meta" }, `В резерве: ${points(user.reserved)}. Баллы дают за регистрацию, бесплатные материалы и продажи.`)
    ),
    el(
      "div",
      { class: "counters" },
      counter(profile.purchases, "Мои покупки", "/pages/purchases.html"),
      counter(profile.materials, "Мои публикации", "/pages/sales.html"),
      counter(profile.waiting, "Ждут подтверждения", "/pages/purchases.html")
    ),
    el("h2", {}, "История баллов"),
    el("ul", { class: "rows" }, history.map(historyRow))
  );
  document.getElementById("status").remove();
} catch (error) {
  showStatus(document.getElementById("status"), error.message);
}
