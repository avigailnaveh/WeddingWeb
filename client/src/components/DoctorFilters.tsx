import { Circle } from "lucide-react";

export type SortOption = "recommendations" | "distance";
export type GenderFilter = "all" | "male" | "female";
export type HmoFilter = "all" | string;

type LatLng = { lat: number; lng: number };

interface DoctorFiltersProps {
  sortBy: SortOption;
  setSortBy: (sort: SortOption) => void;

  genderFilter: GenderFilter;
  setGenderFilter: (gender: GenderFilter) => void;

  memberLocation?: LatLng | null;

  categoryTitle?: string;
}

export default function DoctorFilters({
  sortBy,
  setSortBy,
  genderFilter,
  setGenderFilter,
  memberLocation,
  categoryTitle,
}: DoctorFiltersProps) {
  const handleGenderClick = () => {
    if (genderFilter === "all") setGenderFilter("female");
    else if (genderFilter === "female") setGenderFilter("male");
    else setGenderFilter("all");
  };

  const getGenderLabel = () => {
    if (genderFilter === "female") return "אישה";
    if (genderFilter === "male") return "גבר";
    return "מגדר";
  };

  const pillBase =
    "flex-1 inline-flex items-center justify-center gap-1.5 rounded-full border px-3 py-2 text-sm font-medium transition select-none cursor-pointer whitespace-nowrap";
  const pillIdle = "border-border bg-background text-muted-foreground hover:bg-accent";
  const pillActive = "border-foreground/20 bg-background text-foreground shadow-[0_2px_8px_rgba(0,0,0,0.1)] font-bold";

  const isDistanceDisabled = !memberLocation?.lat || !memberLocation?.lng;

  return (
    <div dir="rtl" className="w-full">
      {categoryTitle && <h2 className="text-center text-2xl font-black text-foreground mb-3">{categoryTitle}</h2>}

      <div className="flex items-center gap-2 w-full">
        {/* מיקום - toggle with המלצות */}
        <button
          type="button"
          onClick={() => setSortBy("distance")}
          disabled={isDistanceDisabled}
          className={[
            pillBase,
            sortBy === "distance" ? pillActive : pillIdle,
            isDistanceDisabled ? "opacity-50 cursor-not-allowed" : "",
          ].join(" ")}
        >
          <span>מיקום</span>
        </button>

        {/* המלצות - toggle with מיקום */}
        <button
          type="button"
          onClick={() => setSortBy("recommendations")}
          className={[pillBase, sortBy === "recommendations" ? pillActive : pillIdle].join(" ")}
        >
          <span>המלצות</span>
        </button>

        {/* מגדר */}
        <button
          type="button"
          onClick={handleGenderClick}
          className={[pillBase, genderFilter !== "all" ? pillActive : pillIdle].join(" ")}
        >
          <span>{getGenderLabel()}</span>
          <Circle
            size={14}
            fill={genderFilter === "female" ? "#ec4899" : genderFilter === "male" ? "#3b82f6" : "none"}
            stroke={genderFilter === "all" ? "currentColor" : "none"}
          />
        </button>
      </div>
    </div>
  );
}
