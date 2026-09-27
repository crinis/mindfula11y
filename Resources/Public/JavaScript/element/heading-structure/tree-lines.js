function computeTreeLines(depths) {
  return depths.map((depth, index) => ({
    lanes: Array.from({ length: depth }, (_, laneDepth) => laneState(depths, index, laneDepth, depth)),
    parent: (depths[index + 1] ?? 0) > depth
  }));
}
function laneState(depths, index, laneDepth, rowDepth) {
  const ancestorContinues = hasLaterChildAtDepth(depths, index, laneDepth + 1);
  if (laneDepth === rowDepth - 1) {
    return ancestorContinues ? "branch" : "last";
  }
  return ancestorContinues ? "pass" : "none";
}
function hasLaterChildAtDepth(depths, index, childDepth) {
  for (let later = index + 1; later < depths.length; later++) {
    const laterDepth = depths[later] ?? 0;
    if (laterDepth < childDepth) {
      return false;
    }
    if (laterDepth === childDepth) {
      return true;
    }
  }
  return false;
}
export {
  computeTreeLines
};
