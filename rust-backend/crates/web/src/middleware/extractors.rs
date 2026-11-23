use axum::{
    async_trait,
    extract::FromRequestParts,
    http::{request::Parts, StatusCode},
    response::{IntoResponse, Redirect, Response},
    Json,
};
use domain::models::customer::CustomerSession;
use tower_sessions::Session;

use crate::state::{AdminSession, SessionHelper};

/// Extractor that requires a logged-in customer, redirecting to /login if unauthenticated
pub struct RequireCustomer(pub CustomerSession);

#[async_trait]
impl<S> FromRequestParts<S> for RequireCustomer
where
    S: Send + Sync,
{
    type Rejection = Response;

    async fn from_request_parts(parts: &mut Parts, state: &S) -> Result<Self, Self::Rejection> {
        let session = Session::from_request_parts(parts, state)
            .await
            .map_err(|e| e.into_response())?;

        if let Some(customer) = SessionHelper::get_customer(&session).await {
            Ok(RequireCustomer(customer))
        } else {
            let is_api = parts.uri.path().starts_with("/api")
                || parts
                    .headers
                    .get("accept")
                    .and_then(|h| h.to_str().ok())
                    .map(|a| a.contains("application/json"))
                    .unwrap_or(false);

            if is_api {
                Err((
                    StatusCode::UNAUTHORIZED,
                    Json(serde_json::json!({
                        "ok": false,
                        "error": "Authentication required"
                    })),
                )
                    .into_response())
            } else {
                let next = parts.uri.path_and_query().map(|pq| pq.as_str()).unwrap_or("/account");
                let redirect_to = format!("/login?next={}", next);
                Err(Redirect::to(&redirect_to).into_response())
            }
        }
    }
}

/// Extractor for an optional customer session
pub struct OptionalCustomer(pub Option<CustomerSession>);

#[async_trait]
impl<S> FromRequestParts<S> for OptionalCustomer
where
    S: Send + Sync,
{
    type Rejection = Response;

    async fn from_request_parts(parts: &mut Parts, state: &S) -> Result<Self, Self::Rejection> {
        let session = Session::from_request_parts(parts, state)
            .await
            .map_err(|e| e.into_response())?;

        let customer = SessionHelper::get_customer(&session).await;
        Ok(OptionalCustomer(customer))
    }
}

/// Extractor that requires a logged-in admin user
pub struct RequireAdmin(pub AdminSession);

#[async_trait]
impl<S> FromRequestParts<S> for RequireAdmin
where
    S: Send + Sync,
{
    type Rejection = Response;

    async fn from_request_parts(parts: &mut Parts, state: &S) -> Result<Self, Self::Rejection> {
        let session = Session::from_request_parts(parts, state)
            .await
            .map_err(|e| e.into_response())?;

        if let Some(user) = SessionHelper::get_user(&session).await {
            Ok(RequireAdmin(user))
        } else {
            let is_api = parts.uri.path().starts_with("/api")
                || parts
                    .headers
                    .get("accept")
                    .and_then(|h| h.to_str().ok())
                    .map(|a| a.contains("application/json"))
                    .unwrap_or(false);

            if is_api {
                Err((
                    StatusCode::UNAUTHORIZED,
                    Json(serde_json::json!({
                        "ok": false,
                        "error": "Admin authentication required"
                    })),
                )
                    .into_response())
            } else {
                Err(Redirect::to("/admin/login.php").into_response())
            }
        }
    }
}

/// Extractor for optional admin session
pub struct OptionalAdmin(pub Option<AdminSession>);

#[async_trait]
impl<S> FromRequestParts<S> for OptionalAdmin
where
    S: Send + Sync,
{
    type Rejection = Response;

    async fn from_request_parts(parts: &mut Parts, state: &S) -> Result<Self, Self::Rejection> {
        let session = Session::from_request_parts(parts, state)
            .await
            .map_err(|e| e.into_response())?;

        let user = SessionHelper::get_user(&session).await;
        Ok(OptionalAdmin(user))
    }
}
