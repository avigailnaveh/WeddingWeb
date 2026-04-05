import HeroSection from "@/components/HeroSection";
import CountdownSection from "@/components/CountdownSection";
import CeremonySection from "@/components/CeremonySection";
import TimelineSection from "@/components/TimelineSection";
import RSVPSection from "@/components/RSVPSection";

const Index = () => {
  return (
    <div className="min-h-screen bg-background" dir="rtl">
      <HeroSection />
      <CountdownSection />
      <CeremonySection />
      <TimelineSection />
      <RSVPSection />
      <footer className="py-6 text-center bg-wedding-blush">
        <p className="font-script text-2xl text-wedding-warm">אביה & דודי</p>
        <p className="font-body text-xs text-muted-foreground mt-1">25.05.2026</p>
      </footer>
    </div>
  );
};

export default Index;
