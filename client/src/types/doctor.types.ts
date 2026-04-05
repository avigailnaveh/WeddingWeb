/**
 * Shared type definitions for doctor/professional data
 * Used across ChatPage, DoctorCard, and API calls
 */

import type { ProfessionalProfile } from "@/api/professional";

export type { ProfessionalProfile };

export interface Specialty {
  id: number;
  name: string;
}

export interface Language {
  id: number;
  name: string;
}

export interface Insurance {
  id: number;
  name: string;
}

export interface HealthFund {
  id: number;
  name: string;
}

export interface ClinicAddress {
  id: number;
  address: string;
}

export interface ProfessionalAddress {
  id: number;
  city: string | null;
  street: string | null;
  number: string | null;
  lat: number | 0;
  lng: number | 0;
}

/**
 * Doctor data for display in cards and lists
 * Can be built from ProfessionalProfile or legacy formats
 */
export interface DoctorData {
  id: number;
  
  // Basic info
  full_name: string;
  title?: string | null;
  gender?: string | null;
  email?: string | null;
  phone?: string | null;
  about?: string | null;
  profile_image?: string | null;
  
  // Specialties and expertise (flattened for display)
  expertise?: string[] | string | null;
  
  // Languages
  languages?: string[] | string | null;
  
  // Insurance and HMO (flattened for display)
  hmo?: string[] | string | null;
  insurance?: string[] | string | null;
  professional_insurance?: string | null;
  professional_company?: string | null;
  

  
  // Metrics and ratings
  recommendation_count?: number | null;
  positive_recommendations?: number | null;
  average_rating?: number | null;
  sentiment?: 'pos' | 'neg' | 'neu';
  sentiment_confidence?: number;
  doctor_metrics?: {
    professionalism?: number;
    empathy?: number;
    availability?: number;
    cost?: number;
    clear_explanation?: number;
    patience?: number;
    // Additional possible fields from different sources
    recommendation_count?: number;
    total?: number;
    total_recommendations?: number;
    };
  
  recCount?: {
    likeMe?: number;
    friends?: number;
    colleagues?: number;
    nearMe?: number;
  };

  address?: ProfessionalAddress[] | null;
  
  // Distance (for location-based searches)
  distance?: number;
}

/**
 * User recommendation data
 */
export interface UserRecommendation {
  id: number;
  text: string;
}

/**
 * Convert ProfessionalProfile from server to DoctorData for display
 */
export function profileToDoctor(profile: ProfessionalProfile): DoctorData {
  const doctor: DoctorData = {
    id: profile.id,
    full_name: profile.full_name || '',
    title: profile.title || undefined,
    gender: profile.gender || undefined,
    email: profile.email || undefined,
    phone: profile.phone || undefined,
    about: profile.about || undefined,
    profile_image: profile.profile_image,
    expertise: [],
    languages: [],
    hmo: undefined,
    insurance: undefined,
    professional_insurance: undefined,
    professional_company: undefined,
    address: null,
    recommendation_count: 0,
    positive_recommendations: 0,
    average_rating: undefined,
    sentiment: undefined,
    sentiment_confidence: undefined,
    doctor_metrics: undefined,
    distance: undefined,
  };

  // Map primary specialties
  if (profile.primary_specialties && profile.primary_specialties.length > 0) {
    doctor.expertise = profile.primary_specialties.map((s) => s.name);
  }

  // Add secondary specialties
  if (profile.secondary_specialties && profile.secondary_specialties.length > 0) {
    const secondaryNames = profile.secondary_specialties.map((s) => s.name);
    doctor.expertise = [...(doctor.expertise || []), ...secondaryNames];
  }

  // Map languages
  if (profile.languages && profile.languages.length > 0) {
    doctor.languages = profile.languages.map((l) => l.name);
  }

  // Map insurances
  if (profile.insurances && profile.insurances.length > 0) {
    const insuranceNames = profile.insurances.map((i) => i.name);
    doctor.professional_insurance = insuranceNames.join(', ');
    doctor.insurance = doctor.professional_insurance;
  }

  // Map health funds (HMOs)
  if (profile.health_funds && profile.health_funds.length > 0) {
    const hmoNames = profile.health_funds.map((h) => h.name);
    doctor.professional_company = hmoNames.join(', ');
    doctor.hmo = doctor.professional_company;
  }


  return doctor;
}