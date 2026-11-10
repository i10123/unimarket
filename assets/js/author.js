// Профиль автора: имя, оценка, число материалов и его материалы.

import { el, rating, startPage } from "./common.js";
import { createList } from "./cards.js";

await startPage();

const data = await createList("У автора пока нет материалов.").reload({ author: new URLSearchParams(location.search).get("id") });

// Ответа нет при ошибке сети: её текст уже показан под сеткой.
if (data) {
  const author = data.author;
  const panel = document.getElementById("author");
  const number = (value, caption) => el("p", {}, el("strong", {}, value), caption);

  if (author) {
    document.title = `${author.name} — УниМаркет`;
    panel.append(
      el("div", {}, el("h1", {}, author.name), author.course && el("p", { class: "empty" }, `${author.course} курс`)),
      el(
        "div",
        { class: "author__numbers" },
        number(rating(author.average, author.votes), "рейтинг автора"),
        number(author.votes, "оценок"),
        number(author.materials, "материалов")
      )
    );
  } else {
    panel.append(el("h1", {}, "Автор не найден"));
  }
}
