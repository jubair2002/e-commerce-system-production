#![allow(clippy::result_large_err)]
#![allow(clippy::too_many_arguments)]

pub mod middleware;
pub mod routes;
pub mod state;
pub mod templates;

pub use middleware::*;
pub use routes::build_router;
pub use state::AppState;
pub use templates::init_templates;
