const API_BASE_URL = "http://localhost/myyiiapp/web/index.php";

export interface ProfessionalProfile {
  id: number;
  full_name: string;
  title: string;
  gender: string;
  email: string;
  phone: string;
  about: string;
  profile_image: string | null;
  primary_specialties: Array<{ id: number; name: string }>;
  secondary_specialties: Array<{ id: number; name: string }>;
  languages: Array<{ id: number; name: string }>;
  health_funds: Array<{ id: number; name: string }>;
  insurances: Array<{ id: number; name: string }>;
  clinic_addresses: Array<{ id: number; address: string }>;
}

export interface ProfessionalOptions {
  main_specializations: Array<{ id: number; name: string }>;
  main_care: Array<{ id: number; name: string }>;
  expertises: Array<{ id: number; name: string }>;
  care: Array<{ id: number; name: string }>;
  languages: Array<{ id: number; name: string }>;
  companies: Array<{ id: number; name: string }>;
  insurances: Array<{ id: number; name: string }>;
}

export interface ProfessionalStatistics {
  total_recommendations: number;
  profile_views: number;
  reviews_over_time: Array<{ month: string; reviews: number }>;
  monthly_change: string;
  views_change: string;
  category_metrics?: {
    professionalism?: number;
    empathy?: number;
    availability?: number;
    cost?: number;
    clear_explanation?: number;
    patience?: number;
  };
}

export interface Review {
  id: string;
  firstName: string;
  lastName: string;
  rating: number;
  date: string;
  content: string;
  status: "active" | "flagged";
}

/**
 * בדיקה אם המשתמש הנוכחי הוא רופא
 */
export async function checkIfProfessional(): Promise<{
  ok: boolean;
  is_professional: boolean;
  data?: ProfessionalProfile;
}> {
  const response = await fetch(
    `${API_BASE_URL}?r=professional/get-current-professional`
  );
  return response.json();
}

/**
 * קבלת פרופיל הרופא הנוכחי
 */
export async function getCurrentProfessionalProfile(): Promise<{
  ok: boolean;
  data?: ProfessionalProfile;
}> {
  const response = await fetch(
    `${API_BASE_URL}?r=professional/get-current-professional`
  );
  return response.json();
}

/**
 * קבלת כל האפשרויות הזמינות להוספה
 */
export async function getProfessionalOptions(): Promise<{
  ok: boolean;
  options?: ProfessionalOptions;
}> {
  const response = await fetch(
    `${API_BASE_URL}?r=professional/get-options`
  );
  return response.json();
}

/**
 * הוספת פריט (התמחות, שפה וכו')
 */
export async function addProfessionalItem(
  type: string,
  itemId: number
): Promise<{ ok: boolean; message?: string; error?: string }> {
  const response = await fetch(`${API_BASE_URL}?r=professional/add-item`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify({ type, item_id: itemId }),
  });
  return response.json();
}

/**
 * הסרת פריט
 */
export async function removeProfessionalItem(
  type: string,
  itemId: number
): Promise<{ ok: boolean; message?: string; error?: string }> {
  const response = await fetch(`${API_BASE_URL}?r=professional/remove-item`, {
    method: "DELETE",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify({ type, item_id: itemId }),
  });
  return response.json();
}

/**
 * קבלת סטטיסטיקות הרופא
 */
export async function getProfessionalStatistics(
  professionalId: number
): Promise<{
  ok: boolean;
  data?: ProfessionalStatistics;
}> {
  const response = await fetch(
    `${API_BASE_URL}?r=professional/get-statistics&id=${professionalId}`
  );
  return response.json();
}

/**
 * קבלת המלצות הרופא
 */
export async function getProfessionalReviews(
  professionalId: number,
  status?: "active" | "flagged"
): Promise<{
  ok: boolean;
  reviews?: Review[];
  total?: number;
}> {
  const url = status
    ? `${API_BASE_URL}?r=professional/get-reviews&id=${professionalId}&status=${status}`
    : `${API_BASE_URL}?r=professional/get-reviews&id=${professionalId}`;

  const response = await fetch(url);
  return response.json();
}

/**
 * עדכון פרופיל הרופא
 */
export async function updateProfessionalProfile(data: {
  full_name?: string;
  phone?: string;
  email?: string;
  about?: string;
  add_addresses?: string[];
  delete_addresses?: number[];
}): Promise<{ ok: boolean; message?: string }> {
  const response = await fetch(`${API_BASE_URL}?r=professional/update-profile`, {
    method: "PATCH",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify(data),
  });
  return response.json();
}

/**
 * העלאת תמונת פרופיל
 */
export async function uploadProfileImage(imageFile: File): Promise<{
  ok: boolean;
  message?: string;
  image_url?: string;
  error?: string;
}> {
  const formData = new FormData();
  formData.append("image", imageFile);

  const response = await fetch(`${API_BASE_URL}?r=professional/upload-image`, {
    method: "POST",
    body: formData,
  });
  return response.json();
}

/**
 * סימון המלצה כלא הולמת
 */
export async function flagReview(reviewId: string): Promise<{
  ok: boolean;
  message?: string;
}> {
  const response = await fetch(
    `${API_BASE_URL}?r=professional/flag-review&id=${reviewId}`,
    {
      method: "PATCH",
    }
  );
  return response.json();
}