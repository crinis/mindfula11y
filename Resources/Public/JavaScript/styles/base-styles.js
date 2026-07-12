import { css } from "lit";
import baseCss from "./base.css.js";
import resetCss from "./reset.css.js";
import tokensCss from "./tokens.css.js";
import utilitiesCss from "./utilities.css.js";
const layerOrder = css`
    @layer reset, base, component, utilities;
`;
const baseStyles = [layerOrder, resetCss, tokensCss, baseCss, utilitiesCss];
export {
  baseStyles
};
