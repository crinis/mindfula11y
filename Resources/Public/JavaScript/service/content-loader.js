import AjaxRequest from "@typo3/core/ajax/ajax-request.js";
class ContentLoader {
  constructor() {
    this.cache = /* @__PURE__ */ new Map();
  }
  async load(url) {
    const pending = this.cache.get(url);
    if (pending !== void 0) {
      return pending;
    }
    const request = (async () => {
      const response = await new AjaxRequest(url).get({
        headers: { "Mindfula11y-Structure-Analysis": "1" }
      });
      return await response.resolve();
    })();
    const guarded = request.catch((error) => {
      this.cache.delete(url);
      throw error;
    });
    this.cache.set(url, guarded);
    return guarded;
  }
  invalidate(url) {
    this.cache.delete(url);
  }
}
var content_loader_default = ContentLoader;
export {
  ContentLoader,
  content_loader_default as default
};
