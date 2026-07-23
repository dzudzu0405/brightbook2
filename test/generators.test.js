const { test } = require("node:test");
const assert = require("node:assert/strict");
const { buildWordSearchPuzzle } = require("../lib/generators/word-search");

function letterAt(rows, row, col) {
  return rows[row].split(" ")[col];
}

test("word search: grid shape is 12x12 uppercase letters", () => {
  for (const theme of ["Farm Animals", "Ocean Animals", "Robots"]) {
    const puzzle = buildWordSearchPuzzle(theme, 1);
    assert.equal(puzzle.rows.length, 12, `${theme}: expected 12 rows`);
    for (const row of puzzle.rows) {
      const cells = row.split(" ");
      assert.equal(cells.length, 12, `${theme}: expected 12 cells per row`);
      for (const cell of cells) assert.match(cell, /^[A-Z]$/, `${theme}: cell "${cell}" must be a single uppercase letter`);
    }
  }
});

test("word search: exactly 10 words placed with matching answer keys", () => {
  for (const theme of ["Farm Animals", "Ocean Animals", "Safari Animals", "Dinosaurs"]) {
    for (let page = 1; page <= 3; page++) {
      const puzzle = buildWordSearchPuzzle(theme, page);
      assert.equal(puzzle.words.length, 10, `${theme} page ${page}: expected 10 words`);
      assert.equal(puzzle.answers.length, 10, `${theme} page ${page}: expected 10 answers`);
    }
  }
});

test("word search: every answer's row/col/direction actually spells the word in the grid", () => {
  const directions = { H: [0, 1], V: [1, 0], D: [1, 1] };
  for (const theme of ["Farm Animals", "Ocean Animals", "Safari Animals", "Outer Space", "Dinosaurs"]) {
    for (let page = 1; page <= 5; page++) {
      const puzzle = buildWordSearchPuzzle(theme, page);
      for (const answer of puzzle.answers) {
        const match = answer.match(/^(\w+): row (\d+), col (\d+), direction ([HVD])$/);
        assert.ok(match, `answer "${answer}" should match the expected format`);
        const [, word, rowStr, colStr, dirCode] = match;
        const row = Number(rowStr) - 1, col = Number(colStr) - 1;
        const [dr, dc] = directions[dirCode];
        const spelled = [...word].map((_, i) => letterAt(puzzle.rows, row + dr * i, col + dc * i)).join("");
        assert.equal(spelled, word, `${theme} page ${page}: expected "${word}" at row ${rowStr} col ${colStr} dir ${dirCode}, found "${spelled}"`);
      }
    }
  }
});
