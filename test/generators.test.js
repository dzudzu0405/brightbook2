const { test } = require("node:test");
const assert = require("node:assert/strict");
const { buildWordSearchPuzzle } = require("../lib/generators/word-search");
const { buildMazePuzzle } = require("../lib/generators/maze");

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

test("maze: 9x9 grid uses only S, G, ., # characters", () => {
  for (const theme of ["Farm Animals", "Ocean Animals", "Outer Space"]) {
    for (let page = 1; page <= 3; page++) {
      const maze = buildMazePuzzle(theme, page);
      assert.equal(maze.rows.length, 9, `${theme} page ${page}: expected 9 rows`);
      for (const row of maze.rows) {
        assert.equal(row.length, 9, `${theme} page ${page}: expected 9 columns`);
        assert.match(row, /^[SG.#]+$/, `${theme} page ${page}: row "${row}" has unexpected characters`);
      }
    }
  }
});

test("maze: solution route is a contiguous, adjacent, wall-free path from S to G", () => {
  for (const theme of ["Farm Animals", "Ocean Animals", "Outer Space"]) {
    for (let page = 1; page <= 3; page++) {
      const maze = buildMazePuzzle(theme, page);
      const cells = maze.cells.split(" -> ").map(cell => {
        const m = cell.match(/^R(\d+)C(\d+)$/);
        assert.ok(m, `cell "${cell}" should match RxCy format`);
        return [Number(m[1]) - 1, Number(m[2]) - 1];
      });
      assert.ok(cells.length >= 2, "solution route should have at least a start and a goal cell");

      const grid = maze.rows.map(row => [...row]);
      const [startRow, startCol] = cells[0];
      const [goalRow, goalCol] = cells[cells.length - 1];
      assert.equal(grid[startRow][startCol], "S", `${theme} page ${page}: first cell should be S`);
      assert.equal(grid[goalRow][goalCol], "G", `${theme} page ${page}: last cell should be G`);

      for (let i = 1; i < cells.length; i++) {
        const [pr, pc] = cells[i - 1], [r, c] = cells[i];
        const stepDistance = Math.abs(pr - r) + Math.abs(pc - c);
        assert.equal(stepDistance, 1, `${theme} page ${page}: step ${i} is not a single orthogonal move`);
      }
      for (const [r, c] of cells) {
        assert.notEqual(grid[r][c], "#", `${theme} page ${page}: solution route passes through a wall at R${r + 1}C${c + 1}`);
      }
    }
  }
});
