# Vision and Growth

Long-term plans for Wildland Ecotech's technology projects and organizational growth. Website and platform plans are tracked separately in [Planned Features](../06-planned-features.md).

## Ecotech Vision

### Drone Surveys

Regular aerial surveys for year-over-year change detection across restoration areas. Data feeds into grant applications and public reporting on the website. The first survey of Brown Gulch watershed has been completed, with restoration now expanding into newly surveyed areas.

### 3D Terrain Modeling and Watershed Analysis

- Use publicly available LIDAR data to create 3D terrain models of project areas
- Load models into a 3D rendering engine (Unity or Unreal)
- Simulate water flow based on topography and geology
- Calculate total watershed volume to quantify water storage impact of restoration work

### Photogrammetry for Streamflow Measurement

- Phone-based photogrammetry to 3D-scan individual Beaver Dam Analogs and their ponds
- Generate depth maps to measure pond volume
- Estimate streamflow by measuring multiple ponds along a watershed
- Addresses a gap in current hydrology research: few good automated tools exist for measuring streamflow; most methods require tedious manual sampling across multiple locations

### IoT Solar-Powered Irrigation System

A mesh network of solar-powered water barrels for irrigating raised beds in the restoration area.

**Design:**

- Each barrel has 1-2 solar panels, a pump, and IoT sensors
- Sensors monitor water level, battery charge, solar input, and pump status
- Barrels communicate as a mesh network, automatically balancing water distribution
- Fill storage tanks at the top of the hill; the network distributes water downhill automatically
- Barrels sourced from local manufacturers (nutritional supplement company donating food-safe barrels)

**Long-term vision:**

- Real-time dashboard on the Wildland Ecotech website showing system status
- Open-source hardware and software designs

### Remote Monitoring (New York Property)

- Solar-powered live streaming cameras and microphones
- Automatic species identification via audio/visual AI
- Solar-powered boat for aquatic ecosystem surveys
- Bioremediation studies: water, soil, and plant tissue sampling

## Organizational Growth

### Volunteer Program

- Whole Foods volunteer partnership (up to 20 paid employees per store per event)
- Expanding partnerships with new landowners in the High Park burn scar
- Drone survey data enabling targeted expansion into new restoration areas

### Grant Strategy

Priority order for applications:

1. **CWCB Watershed Restoration** — Rolling applications, open now
2. **COSWAP** — Applications expected September 2026
3. **CSFS FRWRM** — Applications expected fall 2026
4. **RESTORE Colorado** — Applications expected fall 2026
5. **USDA CWDG** — Federal program, higher complexity, pursue after first successful state grant

### Education Expansion

- Scale beaver dam workshop kits for classrooms statewide
- Partner with educational organizations for field trips (high school and college students doing actual BDA construction)
- Develop curriculum materials around watershed restoration and water conservation

### Community Partnerships

- Food Not Bombs Fort Collins — Ongoing food production and weekly meal service
- CSU STEM 4 Kids — Hands-on workshops
- Whole Foods Market — Volunteers, in-kind donations, and future grant programs
- Local manufacturers — Barrel donations for IoT irrigation system
- Private landowners — Expanding restoration access across the Front Range

## Future Repositories

Alongside the [website repository](https://github.com/wildland-ecotech/wildland.eco), planned open-source projects under the [wildland-ecotech](https://github.com/wildland-ecotech) GitHub organization include:

- IoT irrigation firmware
- Photogrammetry tools
- 3D terrain modeling pipeline
