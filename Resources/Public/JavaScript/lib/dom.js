const scrollIntoViewCentered = (element) => {
  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  element.scrollIntoView({ block: "center", behavior: reduceMotion ? "auto" : "smooth" });
};
export {
  scrollIntoViewCentered
};
