// Парсинг блока отзывов авито из HTML-страницы профиля пользователя.
// Использует data-marker атрибуты — они стабильнее случайных CSS-классов.

import { JSDOM } from "jsdom";

function parseReview(doc, i) {
  const body = doc.querySelector(`[data-marker="review(${i})/body"]`);
  if (!body) return null;

  const scoreMeta = doc.querySelector(`[data-marker="review(${i})/score"] meta[itemprop="ratingValue"]`);
  const score = scoreMeta ? Number(scoreMeta.getAttribute("content")) : null;

  const authorEl = doc.querySelector(`[data-marker="review(${i})/header/title"]`);
  const author = authorEl ? authorEl.textContent.trim() : null;

  const subtitleEl = doc.querySelector(`[data-marker="review(${i})/header/subtitle"]`);
  const subtitle = subtitleEl ? subtitleEl.textContent.trim() : null;
  const [dateRaw, role] = subtitle ? subtitle.split("·").map((s) => s.trim()) : [null, null];

  const stageEl = doc.querySelector(`[data-marker="review(${i})/stage"]`);
  const stageText = stageEl ? stageEl.textContent.trim() : null;
  const stageParts = stageText ? stageText.split("·").map((s) => s.trim()) : [];
  const stage = stageParts[0] || null;

  const itemTitleEl = doc.querySelector(`[data-marker="review(${i})/itemTitle"]`);
  const itemTitle = itemTitleEl ? itemTitleEl.textContent.trim() : (stageParts[1] || null);

  const textEl = doc.querySelector(`[data-marker="review(${i})/text-section/text"]`);
  const text = textEl ? textEl.textContent.trim() : null;

  const answerEl = doc.querySelector(`[data-marker="review(${i})/answer"]`);
  let sellerReply = null;
  if (answerEl) {
    const replyTextEl = answerEl.querySelector(`[data-marker="review(${i})/text-section/text"]`);
    sellerReply = replyTextEl ? replyTextEl.textContent.trim() : null;
  }

  return { index: i, author, date: dateRaw, role, score, stage, item_title: itemTitle, text, seller_reply: sellerReply };
}

export function parseAvitoReviews(html) {
  const dom = new JSDOM(html);
  const doc = dom.window.document;

  const ratingEl = doc.querySelector('[data-marker="ratingSummary/rating"]');
  const rating = ratingEl ? parseFloat(ratingEl.textContent.replace(",", ".")) : null;

  const descEl = doc.querySelector('[data-marker="ratingSummary/description"]');
  const totalMatch = descEl?.textContent.match(/(\d+)/);
  const total = totalMatch ? Number(totalMatch[1]) : null;

  const distribution = {};
  doc.querySelectorAll('[data-marker="ratingSummary/rowStars"]').forEach((row) => {
    const starMeta = row.querySelector('meta[itemprop="ratingValue"]');
    const stars = starMeta ? Number(starMeta.getAttribute("content")) : null;
    const rowEl = row.closest('[class*="row-"]') || row.parentElement?.parentElement;
    const titleEl = rowEl?.querySelector('[data-marker="ratingSummary/rowTitle"]');
    const count = titleEl ? Number(titleEl.textContent.trim()) : 0;
    if (stars !== null) distribution[stars] = count;
  });

  const reviews = [];
  for (let i = 0; ; i++) {
    const review = parseReview(doc, i);
    if (!review) break;
    reviews.push(review);
  }

  return { summary: { rating, total, distribution }, reviews };
}
