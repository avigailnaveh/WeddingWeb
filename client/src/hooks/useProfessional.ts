import { useState, useEffect } from "react";
import {
  checkIfProfessional,
  getCurrentProfessionalProfile,
  getProfessionalStatistics,
  getProfessionalReviews,
  ProfessionalProfile,
  ProfessionalStatistics,
  Review,
} from "@/api/professional";

export function useProfessional() {
  const [isProfessional, setIsProfessional] = useState<boolean>(false);
  const [profile, setProfile] = useState<ProfessionalProfile | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    async function checkAndLoad() {
      try {
        const result = await checkIfProfessional();
        setIsProfessional(result.is_professional);
        if (result.is_professional && result.data) {
          setProfile(result.data);
        }
      } catch (error) {
        console.error("Error checking professional status:", error);
        setIsProfessional(false);
      } finally {
        setLoading(false);
      }
    }

    checkAndLoad();
  }, []);

  const refreshProfile = async () => {
    try {
      const result = await getCurrentProfessionalProfile();
      if (result.ok && result.data) {
        setProfile(result.data);
      }
    } catch (error) {
      console.error("Error refreshing profile:", error);
    }
  };

  return {
    isProfessional,
    profile,
    loading,
    refreshProfile,
  };
}

export function useProfessionalStatistics(professionalId: number) {
  const [statistics, setStatistics] = useState<ProfessionalStatistics | null>(
    null
  );
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!professionalId || professionalId === 0) {
      setLoading(false);
      return;
    }

    async function loadStatistics() {
      try {
        const result = await getProfessionalStatistics(professionalId);
        if (result.ok && result.data) {
          setStatistics(result.data);
        }
      } catch (error) {
        console.error("Error loading statistics:", error);
      } finally {
        setLoading(false);
      }
    }

    loadStatistics();
  }, [professionalId]);

  return { statistics, loading };
}

export function useProfessionalReviews(
  professionalId: number,
  statusFilter?: "active" | "flagged"
) {
  const [reviews, setReviews] = useState<Review[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!professionalId || professionalId === 0) {
      setLoading(false);
      return;
    }

    async function loadReviews() {
      try {
        const result = await getProfessionalReviews(professionalId, statusFilter);
        if (result.ok && result.reviews) {
          setReviews(result.reviews);
        }
      } catch (error) {
        console.error("Error loading reviews:", error);
      } finally {
        setLoading(false);
      }
    }

    loadReviews();
  }, [professionalId, statusFilter]);

  const refreshReviews = async () => {
    if (!professionalId || professionalId === 0) return;
    
    try {
      setLoading(true);
      const result = await getProfessionalReviews(professionalId, statusFilter);
      if (result.ok && result.reviews) {
        setReviews(result.reviews);
      }
    } catch (error) {
      console.error("Error refreshing reviews:", error);
    } finally {
      setLoading(false);
    }
  };

  return { reviews, loading, refreshReviews };
}