use axum::{
    extract::State,
    response::{IntoResponse, Response},
};

use crate::state::AppState;

pub async fn metrics_handler(State(state): State<AppState>) -> Response {
    let pool_size = state.db.size();
    let idle_conns = state.db.num_idle();

    let metrics = format!(
        "# HELP jinish_potro_up Whether the service is up\n\
         # TYPE jinish_potro_up gauge\n\
         jinish_potro_up 1\n\
         # HELP jinish_potro_db_pool_size Total connections in pool\n\
         # TYPE jinish_potro_db_pool_size gauge\n\
         jinish_potro_db_pool_size {}\n\
         # HELP jinish_potro_db_pool_idle Idle connections in pool\n\
         # TYPE jinish_potro_db_pool_idle gauge\n\
         jinish_potro_db_pool_idle {}\n\
         # HELP jinish_potro_info Build and version metadata\n\
         # TYPE jinish_potro_info gauge\n\
         jinish_potro_info{{version=\"0.1.0\",runtime=\"tokio-axum\"}} 1\n",
        pool_size, idle_conns
    );

    (
        [(axum::http::header::CONTENT_TYPE, "text/plain; version=0.0.4; charset=utf-8")],
        metrics,
    )
        .into_response()
}
