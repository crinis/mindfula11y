/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2025  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

/**
 * @file content-fetcher.js
 * @description Service class for fetching and caching HTML content from preview URLs.
 */
import AjaxRequest from "@typo3/core/ajax/ajax-request.js";

/**
 * Service class for fetching and caching HTML content from preview URLs.
 *
 * This service provides centralized content fetching with caching to avoid
 * duplicate network requests and improve performance across multiple components.
 *
 * Features:
 * - Static caching with concurrency handling
 * - Proper headers for TYPO3 backend requests
 * - Promise-based API for async operations
 *
 * @class ContentFetcher
 */
export class ContentFetcher {
  /**
   * Static cache for preview content, shared across all instances.
   * Keyed by previewUrl. Value is either a string (HTML) or a Promise resolving to string.
   */
  static _previewCache = new Map();

  /**
   * Fetches preview content from the server with proper headers and caching.
   *
   * Caching and concurrency details:
   * - Uses a static Map to cache preview HTML by URL, shared across all instances
   * - If a fetch is already in progress for a URL, returns the same in-flight Promise to all callers,
   *   ensuring only one network request is made for concurrent calls
   * - Once the fetch completes, the resolved HTML is stored in the cache for future calls
   * - If the cache contains the HTML, returns it immediately without network request
   *
   * Error handling:
   * - Throws an error if the network request fails
   * - Throws an error if the response cannot be resolved
   *
   * @param {string} url - The preview URL to fetch content from
   * @returns {Promise<string>} Resolves to the preview HTML content
   * @throws {Error} If the fetch request fails or response cannot be resolved
   */
  static async fetchContent(url) {
    if (!url || typeof url !== 'string') {
      throw new Error('Invalid URL provided to ContentFetcher.fetchContent');
    }

    // Use static cache to avoid duplicate fetches for the same URL
    const cache = ContentFetcher._previewCache;

    if (cache.has(url)) {
      const cached = cache.get(url);
      // If cached value is a Promise (fetch in progress), return it
      if (cached && typeof cached.then === 'function') {
        return cached;
      }
      // If cached value is HTML, return it
      return cached;
    }

    // Start fetch and store the Promise immediately
    const fetchPromise = (async () => {
      try {
        const response = await new AjaxRequest(url).get({
          headers: {
            "Mindfula11y-Structure-Analysis": "1",
          },
        });
        const html = await response.resolve();

        if (!html || typeof html !== 'string') {
          throw new Error(`Invalid response received for URL: ${url}`);
        }

        // Replace the Promise in the cache with the resolved HTML
        cache.set(url, html);
        return html;
      } catch (error) {
        // Remove failed fetch from cache to allow retry
        cache.delete(url);
        throw new Error(`Failed to fetch content from ${url}: ${error.message}`);
      }
    })();

    cache.set(url, fetchPromise);
    return fetchPromise;
  }

  /**
   * Clears the cached content for a specific URL.
   * This should be called when content has been modified server-side.
   *
   * @param {string} url - The URL to clear from cache
   */
  static clearCache(url) {
    ContentFetcher._previewCache.delete(url);
  }
}

export default ContentFetcher;
