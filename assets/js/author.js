// Профиль автора: имя, контакт, оценка, число материалов и его материалы.

import { el, plural, rating, startPage } from "./common.js";
import { createList } from "./cards.js";

await startPage();

const data = await createList("У автора пока нет материалов.").reload({ author: new URLSearchParams(location.search).get("id") });

// Ответа нет при ошибке сети: её текст уже показан под сеткой.
if (data) {
  const author = data.author;
  const panel = document.getElementById("author");

  if (author) {
    document.title = `${author.name} — УниМаркет`;
    // Показываем только то, что есть: пустые «нет оценок» и «0 материалов» не нужны.
    const chips = [
      author.course && `${author.course} курс`,
      author.materials > 0 && `${author.materials} ${plural(author.materials, ["материал", "материала", "материалов"])}`,
      author.votes > 0 && `${rating(author.average, author.votes)} · ${author.votes} ${plural(author.votes, ["оценка", "оценки", "оценок"])}`,
    ].filter(Boolean);

    panel.append(
      el("div", { class: "avatar", "aria-hidden": "true" }, author.name.slice(0, 1).toUpperCase()),
      el(
        "div",
        { class: "author__info" },
        el("h1", {}, author.name),
        chips.length > 0 && el("p", { class: "author__chips" }, chips.map((chip) => el("span", {}, chip))),
        author.contact && el("p", { class: "author__contact" }, `Связаться: ${author.contact}`)
      )
    );
  } else {
    panel.append(el("h1", {}, "Автор не найден"));
  }
}
