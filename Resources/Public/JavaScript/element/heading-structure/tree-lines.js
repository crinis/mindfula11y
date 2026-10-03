function computeTreeLines(depths) {
  return depths.map((depth, index) => {
    const lanes = Array.from({ length: depth }, (_, laneDepth) => {
      if (!hasAncestorAtDepth(depths, index, laneDepth)) {
        return "none";
      }
      const ancestorContinues = hasLaterChildAtDepth(depths, index, laneDepth + 1);
      if (laneDepth === depth - 1) {
        return ancestorContinues ? "branch" : "last";
      }
      return ancestorContinues ? "pass" : "none";
    });
    if (hasLaterChildAtDepth(depths, index, depth + 1)) {
      const incoming = lanes.at(-1);
      lanes.push(incoming === "branch" || incoming === "last" ? "drop" : "root");
    }
    return lanes;
  });
}
function hasAncestorAtDepth(depths, index, ancestorDepth) {
  for (let earlier = index - 1; earlier >= 0; earlier--) {
    const earlierDepth = depths[earlier] ?? 0;
    if (earlierDepth <= ancestorDepth) {
      return earlierDepth === ancestorDepth;
    }
  }
  return false;
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
