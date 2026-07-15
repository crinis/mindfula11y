import { StructureAnalysisError } from "../../lib/structure/error.js";
import {
  isStructureAnalysisReadyMessage,
  parsePortMessage,
  STRUCTURE_ANALYSIS_PROTOCOL
} from "../../lib/structure/protocol.js";
const LOAD_TIMEOUT = 15e3;
const POST_LOAD_GRACE = 2e3;
const STRUCTURE_VIEWPORTS = {
  mobile: { width: 375, height: 812 },
  desktop: { width: 1280, height: 900 }
};
const abortReason = (signal) => signal.reason ?? new DOMException("Structure analysis rendering was cancelled.", "AbortError");
class RenderedPageLoader {
  constructor(service) {
    this.service = service;
  }
  async load(viewport, parent, signal, options) {
    signal.throwIfAborted();
    const ticket = await this.service.issueTicket(options.pageId, options.languageId, signal);
    signal.throwIfAborted();
    const frame = this.createFrame(viewport);
    parent.append(frame);
    try {
      return await this.waitForResult(frame, ticket, viewport, options, signal);
    } finally {
      frame.remove();
    }
  }
  createFrame(viewport) {
    const frame = document.createElement("iframe");
    const dimensions = STRUCTURE_VIEWPORTS[viewport];
    frame.dataset.structureAnalysisFrame = viewport;
    frame.width = `${dimensions.width}`;
    frame.height = `${dimensions.height}`;
    frame.tabIndex = -1;
    frame.setAttribute("aria-hidden", "true");
    frame.style.cssText = "position:fixed;inset-block-start:0;inset-inline-start:0;z-index:-1;pointer-events:none;border:0;opacity:0;";
    frame.setAttribute("sandbox", "allow-scripts");
    frame.setAttribute("title", `Structure analysis: ${viewport}`);
    frame.referrerPolicy = "no-referrer";
    return frame;
  }
  async waitForResult(frame, ticket, viewport, options, signal) {
    return new Promise((resolve, reject) => {
      const cleanup = new AbortController();
      let done = false;
      let port = null;
      let graceTimeout = null;
      const settle = (callback) => {
        if (done) {
          return;
        }
        done = true;
        window.clearTimeout(timeout);
        if (graceTimeout !== null) {
          window.clearTimeout(graceTimeout);
        }
        port?.close();
        cleanup.abort();
        callback();
      };
      const timeout = window.setTimeout(
        () => settle(
          () => reject(
            new StructureAnalysisError("timeout", "Timed out while rendering the frontend preview.")
          )
        ),
        LOAD_TIMEOUT
      );
      const handleAbort = () => settle(() => reject(abortReason(signal)));
      const handleFrameLoad = () => {
        if (port !== null || graceTimeout !== null) {
          return;
        }
        graceTimeout = window.setTimeout(
          () => settle(
            () => reject(
              new StructureAnalysisError(
                "framing",
                "The frontend preview could not be analyzed. It may refuse framing or the analysis session expired."
              )
            )
          ),
          POST_LOAD_GRACE
        );
      };
      const handleReady = (event) => {
        if (port !== null || event.source !== frame.contentWindow || !isStructureAnalysisReadyMessage(event.data, ticket.requestId)) {
          return;
        }
        if (graceTimeout !== null) {
          window.clearTimeout(graceTimeout);
          graceTimeout = null;
        }
        const channel = new MessageChannel();
        port = channel.port1;
        port.onmessage = (message) => {
          const parsed = parsePortMessage(message.data, ticket.requestId, viewport);
          if (parsed === null) {
            return;
          }
          switch (parsed.kind) {
            case "result":
              settle(() => resolve({ headings: parsed.headings, landmarks: parsed.landmarks }));
              return;
            case "error":
              settle(
                () => reject(new StructureAnalysisError(parsed.code, parsed.message, parsed.status))
              );
              return;
            case "invalid-result":
              settle(
                () => reject(
                  new StructureAnalysisError(
                    "payload",
                    "The frontend structure analysis result was too large or malformed."
                  )
                )
              );
              return;
          }
        };
        port.start();
        const initialize = {
          protocol: STRUCTURE_ANALYSIS_PROTOCOL,
          type: "initialize",
          requestId: ticket.requestId,
          viewport,
          headings: options.headings,
          landmarks: options.landmarks
        };
        frame.contentWindow?.postMessage(initialize, "*", [channel.port2]);
      };
      window.addEventListener("message", handleReady, { signal: cleanup.signal });
      frame.addEventListener("load", handleFrameLoad, { signal: cleanup.signal });
      signal.addEventListener("abort", handleAbort, { signal: cleanup.signal });
      if (signal.aborted) {
        handleAbort();
        return;
      }
      frame.src = ticket.url;
    });
  }
}
export {
  RenderedPageLoader
};
