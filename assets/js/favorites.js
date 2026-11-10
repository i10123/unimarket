// Избранное: сохранённые материалы текущего пользователя.

import { startPage } from "./common.js";
import { createList } from "./cards.js";

await startPage();

createList("Пока пусто. Нажми на сердце у карточки в каталоге, чтобы сохранить материал.").reload({ favorites: 1 });
