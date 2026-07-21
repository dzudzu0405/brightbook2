const { WORD_SEARCH_MODE_TYPES, themeElements } = require("../theme");

function cleanWord(value=""){
  return String(value).toUpperCase().replace(/[^A-Z]/g,"").slice(0,10);
}
function wordBank(theme=""){
  const t=String(theme).toLowerCase();
  if(/farm/.test(t))return ["COW","SHEEP","PIG","HORSE","GOAT","DUCK","CHICKEN","ROOSTER","BARN","TRACTOR","HAY","CALF","LAMB","PONY","FENCE","EGGS","FARMER","STABLE","PASTURE","GARDEN"];
  if(/ocean|coral|sea/.test(t))return ["DOLPHIN","TURTLE","WHALE","SHARK","OCTOPUS","CRAB","CORAL","REEF","SHELL","SEAL","FISH","WAVE","KELP","SQUID","LOBSTER","SEAHORSE"];
  if(/safari/.test(t))return ["LION","ZEBRA","GIRAFFE","ELEPHANT","RHINO","HIPPO","CHEETAH","GAZELLE","MONKEY","SAVANNA","ACACIA","LEOPARD"];
  if(/space|astronaut|solar/.test(t))return ["ROCKET","PLANET","MOON","STAR","COMET","ORBIT","ASTRO","MARS","VENUS","SATURN","GALAXY","METEOR"];
  if(/dinosaur/.test(t))return ["DINOSAUR","TREX","RAPTOR","FOSSIL","EGG","JURASSIC","STEGOSAUR","TRICERA","VOLCANO","BONES","TAIL","CLAW"];
  const pieces=themeElements(theme);
  return [...pieces.subjects,...pieces.settings,...pieces.props,theme].map(cleanWord).filter(word=>word.length>=3);
}
function buildWordSearchPuzzle(theme,pageNumber,input={}){
  const size=12;
  const pool=[...new Set(wordBank(theme))].filter(word=>word.length>=3&&word.length<=10);
  const orderedPool=Array.from({length:pool.length},(_,i)=>pool[(pageNumber+i-1)%pool.length]).sort((a,b)=>b.length-a.length);
  const grid=Array.from({length:size},()=>Array(size).fill(""));
  const placements=[];
  const mode=WORD_SEARCH_MODE_TYPES.includes(input.wordSearchMode)?input.wordSearchMode:"Standard Word Search";
  const directions=[
    {code:"H",dr:0,dc:1},
    {code:"V",dr:1,dc:0},
    {code:"D",dr:1,dc:1}
  ];
  const slotPlans={
    "Easy Horizontal Only":[
      {code:"H",row:0,col:0},{code:"H",row:1,col:1},{code:"H",row:2,col:0},{code:"H",row:3,col:1},{code:"H",row:4,col:0},
      {code:"H",row:6,col:0},{code:"H",row:7,col:1},{code:"H",row:8,col:0},{code:"H",row:10,col:0},{code:"H",row:11,col:1}
    ],
    "Challenge Diagonal Mix":[
      {code:"D",row:0,col:0},{code:"D",row:0,col:3},{code:"D",row:1,col:0},{code:"D",row:2,col:2},
      {code:"V",row:0,col:11},{code:"V",row:3,col:10},{code:"V",row:5,col:8},
      {code:"H",row:10,col:0},{code:"H",row:11,col:1},{code:"H",row:8,col:0}
    ],
    "Advanced Longer Words":[
      {code:"D",row:0,col:0},{code:"D",row:0,col:3},{code:"D",row:1,col:0},{code:"D",row:2,col:2},
      {code:"V",row:0,col:11},{code:"V",row:2,col:10},{code:"V",row:4,col:8},
      {code:"H",row:9,col:0},{code:"H",row:10,col:0},{code:"H",row:11,col:1}
    ],
    "Standard Word Search":[
      {code:"D",row:0,col:0},{code:"D",row:0,col:4},{code:"D",row:2,col:0},
      {code:"V",row:0,col:11},{code:"V",row:3,col:10},{code:"V",row:5,col:8},
      {code:"H",row:10,col:0},{code:"H",row:11,col:1},{code:"H",row:8,col:0},{code:"H",row:6,col:0}
    ]
  };
  const slotPlan=slotPlans[mode]||slotPlans["Standard Word Search"];
  const canPlace=(word,row,col,dir)=>[...word].every((letter,index)=>{
    const r=row+dir.dr*index,c=col+dir.dc*index;
    return r<size&&c<size&&(!grid[r][c]||grid[r][c]===letter);
  });
  const placeWord=(word,index,slot=slotPlan[index%slotPlan.length])=>{
    const preferred=directions.find(dir=>dir.code===slot.code)||directions[0];
    const attempts=[{dir:preferred,row:slot.row,col:slot.col},...Array.from({length:144},(_,attempt)=>({dir:preferred,attempt}))];
    for(const option of attempts){
      const dir=option.dir;
      if(option.row!=null&&option.col!=null){
        if(!canPlace(word,option.row,option.col,dir))continue;
        [...word].forEach((letter,i)=>{grid[option.row+dir.dr*i][option.col+dir.dc*i]=letter});
        placements.push({word,answer:`${word}: row ${option.row+1}, col ${option.col+1}, direction ${dir.code}`});
        return true;
      }
      const attempt=option.attempt;
      const maxRow=size-(dir.dr?(word.length):1);
      const maxCol=size-(dir.dc?(word.length):1);
      const row=(index*3+attempt*2+pageNumber)%Math.max(1,maxRow+1);
      const col=(index*5+attempt+pageNumber)%Math.max(1,maxCol+1);
      if(!canPlace(word,row,col,dir))continue;
      [...word].forEach((letter,i)=>{grid[row+dir.dr*i][col+dir.dc*i]=letter});
      placements.push({word,answer:`${word}: row ${row+1}, col ${col+1}, direction ${dir.code}`});
      return true;
    }
    return false;
  };
  for(let slot=0;slot<10;slot++){
    const candidates=slotPlan[slot].code==="D"?[...orderedPool].sort((a,b)=>a.length-b.length):orderedPool;
    for(const word of candidates){
      if(placements.some(item=>item.word===word))continue;
      if(placeWord(word,slot,slotPlan[slot]))break;
    }
  }
  let fillerIndex=1;
  while(placements.length<10){
    const word=`WORD${fillerIndex++}`;
    placeWord(word,placements.length,slotPlan[placements.length]);
  }
  const alphabet="ABCDEFGHIJKLMNOPQRSTUVWXYZ";
  for(let r=0;r<size;r++)for(let c=0;c<size;c++)if(!grid[r][c])grid[r][c]=alphabet[(r*7+c*11+pageNumber)%alphabet.length];
  return {
    mode,
    words:placements.map(item=>item.word),
    rows:grid.map(row=>row.join(" ")),
    answers:placements.map(item=>item.answer)
  };
}

module.exports = { cleanWord, wordBank, buildWordSearchPuzzle };
