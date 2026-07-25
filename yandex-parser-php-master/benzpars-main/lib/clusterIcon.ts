import { STATUS_HEX, type FuelStatus } from "@/lib/types";

export interface ClusterCounts {
  yes: number;
  low: number;
  no: number;
  unknown: number;
}

const SEGMENT_ORDER: FuelStatus[] = ["yes", "low", "no", "unknown"];

/** Повторяет радиус кластера из GL-выражения ["step", point_count, 17, 10, 21, 50, 27]. */
export function radiusForCluster(total: number): number {
  if (total < 10) return 17;
  if (total < 50) return 21;
  return 27;
}

function strokeWidthForRadius(r: number): number {
  if (r <= 17) return 5;
  if (r <= 21) return 6;
  return 7;
}

/** Минимальная доля круга на сегмент, чтобы редкий статус не превращался в волосок. */
const MIN_FRACTION = 0.06;

export function computeDonutSegments(
  counts: ClusterCounts,
  minFraction = MIN_FRACTION
): { status: FuelStatus; fraction: number }[] {
  const total = counts.yes + counts.low + counts.no + counts.unknown;
  if (total <= 0) return [];

  const nonZero = SEGMENT_ORDER.filter((status) => counts[status] > 0);
  if (nonZero.length === 0) return [];
  if (nonZero.length === 1) {
    return [{ status: nonZero[0], fraction: 1 }];
  }

  const boosted = nonZero.map((status) => ({
    status,
    boosted: Math.max(counts[status] / total, minFraction),
  }));
  const boostedSum = boosted.reduce((acc, s) => acc + s.boosted, 0);

  return boosted.map(({ status, boosted: b }) => ({
    status,
    fraction: b / boostedSum,
  }));
}

/** Строит DOM-узел кольца-донат кластера: SVG-кольцо + HTML-оверлей со счётчиком. */
export function buildClusterMarkerEl(
  counts: ClusterCounts,
  total: number,
  label: string
): HTMLDivElement {
  const outerR = radiusForCluster(total);
  const strokeWidth = strokeWidthForRadius(outerR);
  const r = outerR - strokeWidth / 2;
  const size = outerR * 2;
  const c = size / 2;
  const circumference = 2 * Math.PI * r;

  const root = document.createElement("div");
  root.className = "azs-cluster";
  root.style.width = `${size}px`;
  root.style.height = `${size}px`;

  const svgNS = "http://www.w3.org/2000/svg";
  const svg = document.createElementNS(svgNS, "svg");
  svg.setAttribute("width", String(size));
  svg.setAttribute("height", String(size));
  svg.setAttribute("viewBox", `0 0 ${size} ${size}`);

  // Сплошная белая "монета" под кольцом — донат читается как единый значок,
  // а не полая рамка со дырой до карты.
  const fill = document.createElementNS(svgNS, "circle");
  fill.setAttribute("cx", String(c));
  fill.setAttribute("cy", String(c));
  fill.setAttribute("r", String(r - strokeWidth / 2));
  fill.setAttribute("fill", "#ffffff");
  svg.appendChild(fill);

  // Тонкий светлый разделитель между цветными сегментами вместо тёмной рамки.
  const backdrop = document.createElementNS(svgNS, "circle");
  backdrop.setAttribute("cx", String(c));
  backdrop.setAttribute("cy", String(c));
  backdrop.setAttribute("r", String(r));
  backdrop.setAttribute("fill", "none");
  backdrop.setAttribute("stroke", "#ffffff");
  backdrop.setAttribute("stroke-width", String(strokeWidth));
  svg.appendChild(backdrop);

  const segments = computeDonutSegments(counts);
  let cursor = 0;
  for (const { status, fraction } of segments) {
    const dash = fraction * circumference;
    const circle = document.createElementNS(svgNS, "circle");
    circle.setAttribute("cx", String(c));
    circle.setAttribute("cy", String(c));
    circle.setAttribute("r", String(r));
    circle.setAttribute("fill", "none");
    circle.setAttribute("stroke", STATUS_HEX[status]);
    circle.setAttribute("stroke-width", String(strokeWidth));
    circle.setAttribute(
      "stroke-dasharray",
      `${dash} ${circumference - dash}`
    );
    circle.setAttribute("stroke-dashoffset", String(-cursor));
    circle.setAttribute("transform", `rotate(-90 ${c} ${c})`);
    svg.appendChild(circle);
    cursor += dash;
  }

  root.appendChild(svg);

  const countEl = document.createElement("span");
  countEl.className = "azs-cluster__count";
  countEl.textContent = label;
  root.appendChild(countEl);

  return root;
}
