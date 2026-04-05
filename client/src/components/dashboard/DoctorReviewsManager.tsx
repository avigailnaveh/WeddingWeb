import { useEffect, useMemo, useState } from "react";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Badge } from "@/components/ui/badge";
import {
  MessageSquare,
  Flag,
  CheckCircle2,
  Filter,
  ChevronDown,
} from "lucide-react";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdownmenu";
import { useToast } from "@/hooks/useToast";
import { Review, flagReview } from "@/api/professional";

interface DoctorReviewsManagerProps {
  reviews: Review[];
  loading: boolean;
  onRefresh: () => void;
}

const DoctorReviewsManager = ({
  reviews: initialReviews,
  loading,
  onRefresh,
}: DoctorReviewsManagerProps) => {
  const { toast } = useToast();
  const [reviews, setReviews] = useState<Review[]>(initialReviews);
  const [filter, setFilter] = useState<"all" | "active" | "flagged">("all");
  const [sortOrder, setSortOrder] = useState<"newest" | "oldest">("newest");

  useEffect(() => {
    setReviews(initialReviews);
  }, [initialReviews]);

  const toTime = (dateStr?: string) => {
    if (!dateStr) return 0;
    if (dateStr === 'עכשיו') return Date.now();

    // Handle relative time formats (e.g., "לפני 59 דק", "לפני שעה", "לפני 3 ימים")
    const relativeMatch = dateStr.match(/לפני\s+(\d+)?\s*(דק|דקות|שעה|שעות|יום|ימים|חודש|חודשים|שנה|שנים)/);
    if (relativeMatch) {
      const amount = relativeMatch[1] ? Number(relativeMatch[1]) : 1;
      const unit = relativeMatch[2];
      
      const now = Date.now();
      let milliseconds = 0;

      switch (unit) {
        case 'דק':
        case 'דקות':
          milliseconds = amount * 60 * 1000;
          break;
        case 'שעה':
        case 'שעות':
          milliseconds = amount * 60 * 60 * 1000;
          break;
        case 'יום':
        case 'ימים':
          milliseconds = amount * 24 * 60 * 60 * 1000;
          break;
        case 'חודש':
        case 'חודשים':
          milliseconds = amount * 30 * 24 * 60 * 60 * 1000; // Approximation
          break;
        case 'שנה':
        case 'שנים':
          milliseconds = amount * 365 * 24 * 60 * 60 * 1000; // Approximation
          break;
      }

      return now - milliseconds;
    }

    // Try standard ISO formats first (YYYY-MM-DD, YYYY-MM-DD HH:MM:SS, etc)
    const direct = Date.parse(dateStr);
    if (!Number.isNaN(direct)) return direct;

    // Try DD/MM/YYYY or DD-MM-YYYY format
    const dmyMatch = dateStr.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/);
    if (dmyMatch) {
      const dd = Number(dmyMatch[1]);
      const mm = Number(dmyMatch[2]) - 1; // Month is 0-indexed
      const yyyy = Number(dmyMatch[3]);
      return new Date(yyyy, mm, dd).getTime();
    }

    // Try YYYY/MM/DD or YYYY-MM-DD format (without time)
    const ymdMatch = dateStr.match(/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/);
    if (ymdMatch) {
      const yyyy = Number(ymdMatch[1]);
      const mm = Number(ymdMatch[2]) - 1; // Month is 0-indexed
      const dd = Number(ymdMatch[3]);
      return new Date(yyyy, mm, dd).getTime();
    }

    // Try timestamp with time: DD/MM/YYYY HH:MM:SS
    const dmyTimeMatch = dateStr.match(
      /^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})\s+(\d{1,2}):(\d{1,2}):(\d{1,2})$/
    );
    if (dmyTimeMatch) {
      const dd = Number(dmyTimeMatch[1]);
      const mm = Number(dmyTimeMatch[2]) - 1;
      const yyyy = Number(dmyTimeMatch[3]);
      const hh = Number(dmyTimeMatch[4]);
      const min = Number(dmyTimeMatch[5]);
      const ss = Number(dmyTimeMatch[6]);
      return new Date(yyyy, mm, dd, hh, min, ss).getTime();
    }

    console.warn('Could not parse date:', dateStr);
    return 0;
  };

  const filteredReviews = useMemo(() => {
    return reviews
      .filter((review) => {
        if (filter === "all") return true;
        return review.status === filter;
      })
      .sort((a, b) => {
        const dateA = toTime(a.date);
        const dateB = toTime(b.date);

        if (sortOrder === "newest") {
          return dateB - dateA; // Newest first
        } else {
          return dateA - dateB; // Oldest first
        }
      });
  }, [reviews, filter, sortOrder]);

  const getStatusBadge = (status: Review["status"]) => {
    switch (status) {
      case "active":
        return (
          <Badge
            variant="outline"
            className="bg-success/10 text-success border-success/30"
          >
            <CheckCircle2 className="w-3 h-3 ml-1" />
            פעיל
          </Badge>
        );
      case "flagged":
        return (
          <Badge
            variant="outline"
            className="bg-destructive/10 text-destructive border-destructive/30"
          >
            <Flag className="w-3 h-3 ml-1" />
            מסומן לבדיקה
          </Badge>
        );
    }
  };

  const handleFlag = async (reviewId: string) => {
    try {
      const result = await flagReview(reviewId);

      if (result.ok) {
        setReviews(
          reviews.map((r) =>
            r.id === reviewId ? { ...r, status: "flagged" as const } : r
          )
        );

        toast({
          title: "ההמלצה סומנה",
          description: "ההמלצה סומנה לבדיקה על ידי הצוות",
        });

        onRefresh();
      } else {
        toast({
          title: "שגיאה",
          description: "לא הצלחנו לסמן את ההמלצה",
          variant: "destructive",
        });
      }
    } catch (error) {
      toast({
        title: "שגיאה",
        description: "אירעה שגיאה בסימון ההמלצה",
        variant: "destructive",
      });
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center py-12">
        <p className="text-muted-foreground">טוען המלצות...</p>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div className="flex items-center gap-3">
          <h2 className="ml-auto text-lg font-bold text-foreground">
            המלצות ({reviews.length})
          </h2>
        </div>

        <div className="flex gap-2 ml-auto">
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="outline" className="gap-2">
                <Filter className="w-4 h-4" />
                סינון
                <ChevronDown className="w-4 h-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem onClick={() => setFilter("all")}>
                הכל
              </DropdownMenuItem>
              <DropdownMenuItem onClick={() => setFilter("active")}>
                פעילים
              </DropdownMenuItem>
              <DropdownMenuItem onClick={() => setFilter("flagged")}>
                מסומנים לבדיקה
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>

          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="outline" className="gap-2">
                מיון לפי תאריך
                <ChevronDown className="w-4 h-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem onClick={() => setSortOrder("newest")}>
                חדש לישן
              </DropdownMenuItem>
              <DropdownMenuItem onClick={() => setSortOrder("oldest")}>
                ישן לחדש
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>

      <div className="space-y-4 text-right">
        {filteredReviews.map((review) => {
          // Fix negative relative times (e.g., "לפני -60 דקות" → "לפני 60 דקות")
          const displayDate = review.date?.replace(/לפני\s*-(\d+)/, 'לפני $1') ?? '';

          return (
          <Card key={review.id} className="shadow-card border-0">
            <CardContent className="p-5">
              <div className="space-y-4">
                <div className="flex items-start justify-between gap-4 flex-row-reverse">
                  <div className="flex items-start gap-3 flex-row-reverse">
                    <Avatar className="w-10 h-10">
                      <AvatarFallback className="bg-secondary text-secondary-foreground font-medium">
                        {review.firstName[0] + "" + (review.lastName[0] ?? "")}
                      </AvatarFallback>
                    </Avatar>
                    <div>
                      <div className="flex items-center gap-2 flex-wrap">
                        <span className="text-sm text-muted-foreground self-center">
                          {displayDate}
                        </span>
                      </div>
                    </div>
                  </div>
                  {getStatusBadge(review.status)}
                </div>

                <p className="text-foreground leading-relaxed text-right" dir="rtl">
                  {review.content}
                </p>
                
                {review.status !== "flagged" && (
                  <div className="flex gap-2 pt-2 justify-end">
                    <Button
                      variant="ghost"
                      size="sm"
                      className="flex-row-reverse gap-1.5 text-muted-foreground hover:text-destructive text-right"
                      onClick={() => handleFlag(review.id)}
                    >
                      <Flag className="w-4 h-4" />
                      דווח על המלצה לא הולמת
                    </Button>
                  </div>
                )}

              </div>
            </CardContent>
          </Card>
        )})}

        {filteredReviews.length === 0 && (
          <Card className="shadow-card border-0">
            <CardContent className="py-12 text-center">
              <MessageSquare className="w-12 h-12 text-muted-foreground mx-auto mb-4" />
              <p className="text-muted-foreground">אין המלצות להצגה</p>
            </CardContent>
          </Card>
        )}
      </div>
    </div>
  );
};

export default DoctorReviewsManager;