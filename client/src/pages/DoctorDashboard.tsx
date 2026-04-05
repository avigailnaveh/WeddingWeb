import { useState, useEffect } from "react";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { User, BarChart3, MessageSquare } from "lucide-react";
import DoctorDashboardHeader from "@/components/dashboard/DoctorDashboardHeader";
import DoctorProfileEditor from "@/components/dashboard/DoctorProfileEditor";
import DoctorStatistics from "@/components/dashboard/DoctorStatistics";
import DoctorReviewsManager from "@/components/dashboard/DoctorReviewsManager";
import { useProfessional, useProfessionalStatistics, useProfessionalReviews } from "@/hooks/useProfessional";

const DoctorDashboard = () => {
  const [activeTab, setActiveTab] = useState("profile");
  const { isProfessional, profile, loading: profileLoading, refreshProfile } = useProfessional();
  const { statistics, loading: statsLoading } = useProfessionalStatistics(profile?.id ?? 0);
  const { reviews, loading: reviewsLoading, refreshReviews } = useProfessionalReviews(profile?.id ?? 0);

  if (profileLoading) {
    return (
      <div className="min-h-screen bg-background flex items-center justify-center">
        <p className="text-muted-foreground">טוען...</p>
      </div>
    );
  }

  if (!isProfessional) {
    return (
      <div className="min-h-screen bg-background flex items-center justify-center">
        <div className="text-center">
          <h1 className="text-2xl font-bold mb-2">גישה נדחתה</h1>
          <p className="text-muted-foreground">עמוד זה מיועד לרופאים בלבד</p>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-background">
      {/* <DoctorDashboardHeader profile={profile} /> */}
      
      <div className="max-w-4xl mx-auto px-2 py-2">
        <div className="mb-8 pt-14">
          <h1 className="text-3xl font-bold mb-2">דשבורד רופא</h1>
          <p className="text-muted-foreground">נהל את הפרופיל שלך וצפה בסטטיסטיקות</p>
        </div>

        <Tabs value={activeTab} onValueChange={setActiveTab}  dir="rtl" className="space-y-6">
          <TabsList className="grid w-full max-w-md grid-cols-3 h-auto">
            <TabsTrigger value="profile" className="gap-2 py-3">
              <User className="w-4 h-4" />
              <span className="hidden sm:inline">פרופיל</span>
            </TabsTrigger>
            <TabsTrigger value="statistics" className="gap-2 py-3">
              <BarChart3 className="w-4 h-4" />
              <span className="hidden sm:inline">סטטיסטיקות</span>
            </TabsTrigger>
            <TabsTrigger value="reviews" className="gap-2 py-3">
              <MessageSquare className="w-4 h-4" />
              <span className="hidden sm:inline">ביקורות</span>
            </TabsTrigger>
          </TabsList>

          <TabsContent value="profile">
            <DoctorProfileEditor profile={profile} onUpdate={refreshProfile} />
          </TabsContent>

          <TabsContent value="statistics">
            <DoctorStatistics statistics={statistics} loading={statsLoading} />
          </TabsContent>

          <TabsContent value="reviews">
            <DoctorReviewsManager 
              reviews={reviews} 
              loading={reviewsLoading}
              onRefresh={refreshReviews}
            />
          </TabsContent>
        </Tabs>
      </div>
    </div>
  );
};

export default DoctorDashboard;