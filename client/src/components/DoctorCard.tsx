import React, { useMemo, useState, useEffect } from "react";
import { createPortal } from "react-dom";
import { motion } from "framer-motion";
import { Heart, MapPin, Shield, X, Phone, Mail, User2 } from "lucide-react";

import { ingestRecommendation } from "@/api/ingestRecommendation";
import { deleteRecommendation } from "@/api/recommendations";
import type { DoctorData } from "@/types/doctor.types";

export type { DoctorData };

interface UserRecommendation {
  id: number;
  text: string;
}

interface DoctorCardProps {
  doctor: DoctorData & { distance?: number };
  index?: number;
  userRecommendation?: UserRecommendation;
  onRecommendationUpdate?: (professionalId: number, recommendation: UserRecommendation | null) => void;
  userLocation?: { lat: number; lng: number } | null;
}

/* =========================
   helpers
========================= */

function safeStr(v: unknown, fallback = ""): string {
  if (v === null || v === undefined) return fallback;
  return String(v);
}

function normalizeList(v: unknown): string[] {
  if (!v) return [];
  if (Array.isArray(v)) return v.map((x) => safeStr(x).trim()).filter(Boolean);
  if (typeof v === "string") {
    return v
      .split(/[,|;]+/)
      .map((s) => s.trim())
      .filter(Boolean);
  }
  return [];
}

function uniqNonEmpty(values: Array<string | null | undefined>) {
  const seen = new Set<string>();
  const out: string[] = [];
  for (const v of values) {
    const s = (v ?? "").trim();
    if (!s) continue;
    if (seen.has(s)) continue;
    seen.add(s);
    out.push(s);
  }
  return out;
}

function splitTitleAndName(fullNameRaw: string | null | undefined, explicitTitle?: string | null) {
  const raw = (fullNameRaw ?? "").trim();
  const titleFromProp = (explicitTitle ?? "").trim();

  if (!raw && !titleFromProp) {
    return { title: "", name: "ללא שם" };
  }

  if (titleFromProp) {
    const escaped = titleFromProp.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
    const re = new RegExp(`^${escaped}\\s*`, "u");
    const cleaned = raw.replace(re, "").trim();
    return { title: titleFromProp, name: cleaned || raw || "ללא שם" };
  }

  const candidates = ['ד"ר', "ד״ר", "דר", "ד'ר", "פרופ'", "פרופ״", "פרופ", "פרופסור"];
  for (const t of candidates) {
    if (raw.startsWith(t + " ") || raw === t) {
      const name = raw.slice(t.length).trim();
      return { title: t, name: name || "ללא שם" };
    }
  }

  return { title: "", name: raw || "ללא שם" };
}

function pickMetrics(doctor: DoctorData) {
  const m = doctor.doctor_metrics || {};
  const rc = (doctor as any).recCount || doctor.recCount || {};

  const total =
    (doctor.recommendation_count ??
      (m as any).recommendation_count ??
      (m as any).total ??
      (m as any).total_recommendations ??
      0) as number;

  const friends = rc.friends || 0;
  const colleagues = rc.colleagues || 0;
  const likeMe = rc.likeMe || 0;
  const nearMe = rc.nearMe || 0;

  return { total, friends, colleagues, likeMe, nearMe };
}

function extractCitiesSorted(doctor: DoctorData, userLocation?: { lat: number; lng: number } | null): string[] {
  if (!Array.isArray(doctor.address)) return [];
  const cityAddrs = doctor.address.filter((a) => a?.city);
  if (!cityAddrs.length) return [];

  if (userLocation) {
    const haversine = (lat1: number, lng1: number, lat2: number, lng2: number) => {
      const R = 6371;
      const dLat = ((lat2 - lat1) * Math.PI) / 180;
      const dLng = ((lng2 - lng1) * Math.PI) / 180;
      const a = Math.sin(dLat / 2) ** 2 + Math.cos((lat1 * Math.PI) / 180) * Math.cos((lat2 * Math.PI) / 180) * Math.sin(dLng / 2) ** 2;
      return 2 * R * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    };
    const sorted = [...cityAddrs].sort((a, b) => {
      const da = (a.lat && a.lng) ? haversine(userLocation.lat, userLocation.lng, a.lat, a.lng) : 999999;
      const db = (b.lat && b.lng) ? haversine(userLocation.lat, userLocation.lng, b.lat, b.lng) : 999999;
      return da - db;
    });
    return uniqNonEmpty(sorted.map((a) => a.city));
  }

  return uniqNonEmpty(cityAddrs.map((a) => a.city));
}

function extractInsuranceAndHmo(doctor: DoctorData): string[] {
  const fromCompany: string[] = [];
  if (Array.isArray((doctor as any).company)) {
    (doctor as any).company.forEach((c: any) => {
      if (c?.name) fromCompany.push(c.name);
    });
  }
  const fromProps = [
    ...fromCompany,
    ...normalizeList(doctor.hmo),
    ...normalizeList(doctor.insurance),
    ...normalizeList(doctor.professional_company),
    ...normalizeList(doctor.professional_insurance),
  ];
  return uniqNonEmpty(fromProps);
}

/* =========================
   component
========================= */

export default function DoctorCard({ doctor, index, userRecommendation, onRecommendationUpdate, userLocation }: DoctorCardProps) {
  const { title, name } = useMemo(
    () => splitTitleAndName(doctor.full_name, doctor.title),
    [doctor.full_name, doctor.title]
  );

  const expertiseDisplay = useMemo((): React.ReactNode => {
    const categoryNames = Array.isArray((doctor as any).categoryNames)
      ? (doctor as any).categoryNames
      : [];

    const mainCareArr = Array.isArray((doctor as any).mainCare)
      ? (doctor as any).mainCare
      : [];

    const mainSpecialtyRaw = (doctor as any).mainSpecialty;
    const specialtyRaw = (doctor as any).specialty;

    const mainSpecialty = Array.isArray(mainSpecialtyRaw)
      ? mainSpecialtyRaw
      : mainSpecialtyRaw
        ? [mainSpecialtyRaw]
        : [];

    const specialty = Array.isArray(specialtyRaw)
      ? specialtyRaw
      : specialtyRaw
        ? [specialtyRaw]
        : [];

    const primaryList =
      categoryNames.length > 0
        ? categoryNames
        : mainCareArr.length > 0
          ? mainCareArr
          : [];

    if (primaryList.length === 0) {
      const allExpertise = uniqNonEmpty(normalizeList(doctor.expertise));
      return allExpertise.join(", ");
    }

    const mainFirst = mainSpecialty[0] || "";
    const specialtyFirst = specialty[0] || "";

    if (mainFirst && specialtyFirst && mainFirst !== specialtyFirst) {
      // Build ordered list: bold item (mainFirst + specialtyFirst) first, rest after
      const boldPart = (
        <span key="bold-main">
          <span className="doctorCard__rowText--bold">{mainFirst}</span>, <span className="doctorCard__rowText--bold">{specialtyFirst}</span>
        </span>
      );
      const rest = primaryList.filter((item: string) => item !== mainFirst);
      const parts: React.ReactNode[] = [boldPart];
      rest.forEach((item: string, i: number) => {
        parts.push(<span key={`sep-${i}`}> | </span>);
        parts.push(<span key={`rest-${i}`}>{item}</span>);
      });
      return parts;
    }

    // Bold items that match mainFirst, put it first
    const hasMatch = mainFirst && primaryList.includes(mainFirst);
    if (hasMatch) {
      const rest = primaryList.filter((item: string) => item !== mainFirst);
      const parts: React.ReactNode[] = [
        <span key="bold-main" className="doctorCard__rowText--bold">{mainFirst}</span>
      ];
      rest.forEach((item: string, i: number) => {
        parts.push(<span key={`sep-${i}`}> | </span>);
        parts.push(<span key={`rest-${i}`}>{item}</span>);
      });
      return parts;
    }

    return primaryList.join(" | ");
  }, [doctor]);
  const insuranceHmo = useMemo(() => extractInsuranceAndHmo(doctor), [doctor]);
  const cities = useMemo(() => extractCitiesSorted(doctor, userLocation), [doctor, userLocation]);

  const [metrics, setMetrics] = useState(() => pickMetrics(doctor));

  const [open, setOpen] = useState(false);
  const [text, setText] = useState("");
  const [loading, setLoading] = useState(false);
  const [err, setErr] = useState("");

  const hasRecommended = !!userRecommendation;
  const recommendationId = userRecommendation?.id ?? null;

  const distanceKmLabel = useMemo(() => {
    const d = (doctor as any)?.distance;
    if (d == null) return "";
    const num = Number(d);
    if (!Number.isFinite(num) || num >= 999999) return "";
    return `${Math.round(num * 10) / 10} ק״מ`;
  }, [doctor]);

  useEffect(() => {
    if (open) {
      setText(userRecommendation?.text ?? "");
      setErr("");
    } else {
      setText("");
      setErr("");
    }
  }, [open, userRecommendation]);

  useEffect(() => {
    if (!open) return;
    const prev = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => {
      document.body.style.overflow = prev;
    };
  }, [open]);

  async function submit() {
    const t = text.trim();
    if (!t) return;

    setLoading(true);
    setErr("");

    try {
      const payload: any = {
        member_id: 1,
        professional_id: doctor.id,
        rec_description: t,
      };

      if (recommendationId) {
        payload.recommendation_id = recommendationId;
      }

      const result = await ingestRecommendation(payload);

      const newId = result?.recommendation_id ?? recommendationId ?? null;

      if (onRecommendationUpdate) {
        onRecommendationUpdate(doctor.id, newId ? { id: newId, text: t } : null);
      }

      setMetrics((prev) => ({
        ...prev,
        total: (prev.total || 0) + (hasRecommended ? 0 : 1),
      }));

      setOpen(false);
    } catch (e: any) {
      setErr(e?.message || "שגיאה בשליחת ההמלצה");
    } finally {
      setLoading(false);
    }
  }

  async function handleDelete() {
    if (!recommendationId || !window.confirm("האם את/ה בטוח/ה שברצונך למחוק את ההמלצה?")) return;

    setLoading(true);
    setErr("");

    try {
      await deleteRecommendation(recommendationId);

      if (onRecommendationUpdate) {
        onRecommendationUpdate(doctor.id, null);
      }

      setMetrics((prev) => ({
        ...prev,
        total: Math.max(0, (prev.total || 0) - 1),
      }));

      setOpen(false);
    } catch (e: any) {
      setErr(e?.message || "שגיאה במחיקת ההמלצה");
    } finally {
      setLoading(false);
    }
  }

  

  return (
    <>
      <motion.div
        className="doctorCard"
        dir="rtl"
        initial={{ opacity: 0, y: 6 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.22, delay: (index ?? 0) * 0.03 }}
      >

        {distanceKmLabel && (
          <div className="doctorCard__distance">
            <span>{distanceKmLabel}</span>
          </div>
        )}

        {/* Header */}
        <div className="doctorCard__header">
          <div className="doctorCard__title">
            {title && <span className="doctorCard__titlePrefix">{title}</span>}
            <span>{name}</span>
          </div>
          <User2 className="doctorCard__headerIcon" />
        </div>

        {/* Expertise */}
        {expertiseDisplay && (
          <div className="doctorCard__expertise">
            {expertiseDisplay}
          </div>
        )}

        <div className="doctorCard__rows">
          {cities.length > 0 && (
            <div className="doctorCard__row">
              <MapPin className="doctorCard__rowIcon" />
              <div className="doctorCard__rowText">
                <span className="doctorCard__rowText--bold">{cities[0]}</span>
                {cities.length > 1 && <span> · {cities.slice(1).join(" · ")}</span>}
              </div>
            </div>
          )}

          {insuranceHmo.length > 0 && (
            <div className="doctorCard__row">
              <Shield className="doctorCard__rowIcon" />
              <div className="doctorCard__rowText">{insuranceHmo.join(" / ")}</div>
            </div>
          )}

          {doctor.phone && (
            <div className="doctorCard__row">
              <Phone className="doctorCard__rowIcon" />
              <div className="doctorCard__rowText">{doctor.phone}</div>
            </div>
          )}

          {doctor.email && (
            <div className="doctorCard__row">
              <Mail className="doctorCard__rowIcon" />
              <div className="doctorCard__rowText">{doctor.email}</div>
            </div>
          )}
        </div>

        <div className="doctorCard__divider" />

        <div className="doctorCard__bottom">
          <div className="doctorCard__statsGroup">
            {(metrics.total ?? 0) > 0 && (
              <div className="doctorCard__stats">
                <div className="doctorCard__circle">{metrics.total}</div>
                <div className="doctorCard__labels"><div>המלצות</div></div>
              </div>
            )}
            {metrics.friends > 0 && (
              <div className="doctorCard__stats">
                <div className="doctorCard__circle">{metrics.friends}</div>
                <div className="doctorCard__labels"><div>חברים</div></div>
              </div>
            )}
            {metrics.colleagues > 0 && (
              <div className="doctorCard__stats">
                <div className="doctorCard__circle">{metrics.colleagues}</div>
                <div className="doctorCard__labels"><div>אוטוריטות</div></div>
              </div>
            )}
            {metrics.likeMe > 0 && (
              <div className="doctorCard__stats">
                <div className="doctorCard__circle">{metrics.likeMe}</div>
                <div className="doctorCard__labels"><div>כמוני</div></div>
              </div>
            )}
            {metrics.nearMe > 0 && (
              <div className="doctorCard__stats">
                <div className="doctorCard__circle">{metrics.nearMe}</div>
                <div className="doctorCard__labels"><div>קרובים</div></div>
              </div>
            )}
          </div>

          <button
            className={`doctorCard__recommendBtn ${hasRecommended ? "doctorCard__recommendBtn--active" : ""}`}
            type="button"
            onClick={() => setOpen(true)}
          >
            <span>להמליץ</span>
            <span className={`doctorCard__heartWrap ${hasRecommended ? "doctorCard__heartWrap--filled" : ""}`}>
              <Heart
                className={`doctorCard__heartIcon ${hasRecommended ? "doctorCard__heartIcon--active" : ""}`}
                size={14}
                fill={hasRecommended ? "currentColor" : "none"}
                stroke="currentColor"
              />
            </span>
          </button>
        </div>
      </motion.div>

      {open &&
  createPortal(
    <div className="doctoritaOverlay" dir="rtl">
      <div
        className="doctoritaOverlay__backdrop"
        onClick={() => !loading && setOpen(false)}
      />

      <div className="doctoritaOverlay__panelFull" role="dialog" aria-modal="true">
        <button
          className="doctoritaOverlay__close"
          type="button"
          onClick={() => !loading && setOpen(false)}
          aria-label="סגור"
        >
          <X size={20} />
        </button>

        <div className="doctoritaOverlay__content">
          <div className="doctoritaOverlay__heart">
            <Heart size={28} fill="#ff4fa2" stroke="#ff4fa2" />
          </div>

          <div className="doctoritaOverlay__title">
            {title ? `${title} ${name}` : name}
          </div>

          <div className="doctoritaOverlay__subtitle">
            {expertiseDisplay}
          </div>

          <textarea
            className="doctoritaOverlay__textarea"
            value={text}
            onChange={(e) => setText(e.target.value)}
            placeholder="חברים שלך ישמחו מאוד אם תוסיפי כמה מילים, אבל לא חובה :)"
            disabled={loading}
          />

          {err && <div className="doctoritaOverlay__error">{err}</div>}

          <button
            className="doctoritaOverlay__submit"
            type="button"
            onClick={submit}
            disabled={loading || !text.trim()}
          >
            {loading ? "שולח..." : "שליחת המלצה"}
          </button>

          {hasRecommended && (
            <button
              className="doctoritaOverlay__delete"
              type="button"
              onClick={handleDelete}
              disabled={loading}
            >
              מחיקת המלצה
            </button>
          )}
        </div>
      </div>
    </div>,
    document.body
  )}

    </>
  );
}
