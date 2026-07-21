const { MAZE_LAYOUT_TYPES } = require("../theme");

function mazeStoryPair(theme,pageNumber){
  const t=String(theme||"").toLowerCase();
  const farm=[
    ["baby cow","barn"],["puppy","bone"],["bunny","carrot"],["chicken","baby chicks"],["kitten","milk bowl"],
    ["duckling","pond"],["goat","hay stack"],["pony","stable"],["piglet","mud puddle"],["lamb","pasture gate"]
  ];
  const ocean=[
    ["baby dolphin","family pod"],["sea turtle","pond-like lagoon"],["jellyfish","octopus friend"],["baby fish","aquarium"],
    ["crab","shell home"],["seahorse","coral garden"],["penguin","iceberg"],["seal pup","safe rock"]
  ];
  const safari=[
    ["baby lion","family"],["monkey","banana"],["baby hippo","friend"],["zebra","watering hole"],["giraffe","leafy tree"]
  ];
  const space=[
    ["rocket","moon"],["alien spaceship","planet Earth"],["astronaut","space station"],["comet","star field"],["rover","Mars base"]
  ];
  const adventure=[
    ["pirate parrot","pirate ship"],["sailor boy","ship"],["train","station"],["boat","island"],["child explorer","treasure chest"]
  ];
  let pairs=farm;
  if(/ocean|sea|coral|arctic|penguin/.test(t))pairs=ocean;
  else if(/safari|lion|zebra|giraffe|hippo/.test(t))pairs=safari;
  else if(/space|astronaut|solar|rocket|alien/.test(t))pairs=space;
  else if(/pirate|treasure|train|boat|airplane|car|truck|camping/.test(t))pairs=adventure;
  const [start,goal]=pairs[(pageNumber-1)%pairs.length];
  return {start,goal,mission:`Help the ${start} find the ${goal}`};
}
function mazeLayoutSpec(input,pageNumber){
  const requested=MAZE_LAYOUT_TYPES.includes(input.mazeLayout)?input.mazeLayout:"Mixed Marketplace Variety";
  const rotation=MAZE_LAYOUT_TYPES.filter(item=>item!=="Mixed Marketplace Variety");
  const layout=requested==="Mixed Marketplace Variety"?rotation[(pageNumber-1)%rotation.length]:requested;
  const shapeMap={
    "Classic Rectangle Maze":"large rectangular maze block with straight corridors and a thick outer border",
    "Circular Ring Maze":"round concentric ring maze with curved corridor bands and radial openings",
    "Triangle Pyramid Maze":"triangle or pyramid-shaped maze with straight corridor segments inside the triangular outline",
    "Object-Shaped Maze":"theme-object silhouette maze, such as an apple, carrot, shell, rocket, leaf, or gift shape",
    "House or Barn Maze":"house or barn-shaped maze with a roof outline, simple doorway shape, and rectangular lower body",
    "Animal Silhouette Maze":"simple animal silhouette maze with a clear child-friendly outline, such as cow, bunny, fish, bird, or dinosaur",
    "Adventure Path Maze":"open journey-style maze path with arrows entering and exiting from different page edges"
  };
  return {layout,shape:shapeMap[layout]||shapeMap["Classic Rectangle Maze"]};
}
function buildMazePuzzle(theme,pageNumber,input={}){
  const mazeFromPath=(path)=>{
    const grid=Array.from({length:9},()=>Array(9).fill("#"));
    path.forEach(([row,col],index)=>{
      grid[row][col]=index===0 ? "S" : index===path.length-1 ? "G" : ".";
    });
    const route=path.slice(1).map(([row,col],index)=>{
      const [prevRow,prevCol]=path[index];
      if(row===prevRow&&col===prevCol+1)return "R";
      if(row===prevRow&&col===prevCol-1)return "L";
      if(row===prevRow+1&&col===prevCol)return "D";
      if(row===prevRow-1&&col===prevCol)return "U";
      return "?";
    }).join(", ");
    return {rows:grid.map(row=>row.join("")),route,cells:path.map(([row,col])=>`R${row+1}C${col+1}`).join(" -> ")};
  };
  const variants=[
    mazeFromPath([[0,0],[0,1],[0,2],[1,2],[2,2],[2,3],[2,4],[3,4],[4,4],[4,3],[4,2],[4,1],[5,1],[6,1],[6,2],[6,3],[6,4],[6,5],[6,6],[7,6],[8,6],[8,7],[8,8]]),
    mazeFromPath([[0,0],[1,0],[2,0],[2,1],[2,2],[1,2],[0,2],[0,3],[0,4],[1,4],[2,4],[3,4],[4,4],[4,5],[4,6],[5,6],[6,6],[6,5],[6,4],[7,4],[8,4],[8,5],[8,6],[8,7],[8,8]]),
    mazeFromPath([[0,0],[0,1],[1,1],[2,1],[3,1],[3,2],[3,3],[2,3],[1,3],[1,4],[1,5],[2,5],[3,5],[4,5],[5,5],[5,4],[5,3],[6,3],[7,3],[7,4],[7,5],[7,6],[7,7],[8,7],[8,8]])
  ];
  const maze=variants[(pageNumber-1)%variants.length];
  const story=mazeStoryPair(theme,pageNumber);
  const layout=mazeLayoutSpec(input,pageNumber);
  return {
    rows:maze.rows,
    route:maze.route,
    cells:maze.cells,
    layout:layout.layout,
    shape:layout.shape,
    start:story.start,
    goal:story.goal,
    mission:story.mission,
    legend:"S = start, G = goal, . = open path, # = wall"
  };
}

module.exports = { mazeStoryPair, mazeLayoutSpec, buildMazePuzzle };
