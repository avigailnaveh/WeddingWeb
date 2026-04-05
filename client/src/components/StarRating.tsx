import { Star } from "lucide-react";

interface StarRatingProps {
  rating: number;
  size?: "sm" | "md" | "lg";
  showValue?: boolean;
}

const StarRating = ({ rating, size = "md", showValue = true }: StarRatingProps) => {
  const sizeClasses = {
    sm: "w-4 h-4",
    md: "w-5 h-5",
    lg: "w-6 h-6",
  };

  return (
    <div className="flex items-center gap-1">
      {[1, 2, 3, 4, 5].map((star) => (
        <Star
          key={star}
          className={`${sizeClasses[size]} ${
            star <= rating
              ? "fill-yellow-400 text-yellow-400"
              : "fill-none text-muted-foreground"
          }`}
        />
      ))}
      {showValue && (
        <span className="text-sm font-medium text-muted-foreground mr-1">
          {rating.toFixed(1)}
        </span>
      )}
    </div>
  );
};

export default StarRating;