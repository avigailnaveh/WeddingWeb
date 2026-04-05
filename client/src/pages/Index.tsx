import HeroSection from "@/components/HeroSection";
import CountdownSection from "@/components/CountdownSection";
import CeremonySection from "@/components/CeremonySection";
import TimelineSection from "@/components/TimelineSection";
import InstagramSection from "@/components/InstagramSection";

const Index = () => {
  return (
    <div className="min-h-screen bg-background" dir="rtl">
      <HeroSection />
      <CountdownSection />
      <CeremonySection />
      <TimelineSection />
      <InstagramSection />
      <footer className="py-6 text-center bg-wedding-blush">
        <p className="font-script text-2xl text-wedding-warm">לוסיה & איוון</p>
        <p className="font-body text-xs text-muted-foreground mt-1">29.11.2025</p>
      </footer>
    </div>
  );
};

export default Index;
