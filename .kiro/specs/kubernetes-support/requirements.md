# Requirements Document

## Introductie

Dit document beschrijft de requirements voor het toevoegen van native Kubernetes/OKD/OpenShift ondersteuning aan Coolify. Momenteel ondersteunt Coolify alleen Docker-gebaseerde deployments (StandaloneDocker en SwarmDocker). Deze feature breidt de deployment mogelijkheden uit naar Kubernetes clusters, waardoor gebruikers kunnen kiezen tussen Docker en Kubernetes als deployment target.

De implementatie is gebaseerd op GitHub issue #2390 en volgt het bestaande patroon van "destinations" in Coolify (vergelijkbaar met StandaloneDocker en SwarmDocker).

## Glossarium

- **Kubernetes_Cluster**: Een Kubernetes cluster configuratie die verbindingsgegevens, authenticatie en cluster-specifieke instellingen bevat
- **Kubernetes_Destination**: Een deployment destination binnen een Kubernetes cluster, vergelijkbaar met StandaloneDocker maar voor Kubernetes namespaces
- **Manifest_Generator**: Een service die Coolify applicatie configuraties omzet naar Kubernetes YAML manifests (Deployments, Services, Ingress, etc.)
- **HPA**: Horizontal Pod Autoscaler - Kubernetes resource voor automatische pod scaling
- **Ingress**: Kubernetes resource voor HTTP/HTTPS routing naar services
- **Kubeconfig**: Configuratiebestand voor Kubernetes cluster authenticatie
- **Namespace**: Kubernetes isolatie mechanisme voor resources binnen een cluster
- **OKD**: De community distributie van OpenShift (Red Hat's Kubernetes platform)
- **OpenShift**: Red Hat's enterprise Kubernetes platform met extra features zoals Routes

## Requirements

### Requirement 1: Kubernetes Cluster Beheer

**User Story:** Als een Coolify gebruiker wil ik Kubernetes clusters kunnen toevoegen en beheren, zodat ik applicaties naar Kubernetes kan deployen.

#### Acceptance Criteria

1. WHEN een gebruiker een nieuw Kubernetes cluster toevoegt, THE Kubernetes_Cluster model SHALL de cluster configuratie opslaan inclusief naam, kubeconfig, en context
2. WHEN een gebruiker een kubeconfig uploadt, THE Kubernetes_Cluster SHALL de verbinding valideren door een API health check uit te voeren
3. IF de cluster verbinding faalt, THEN THE Kubernetes_Cluster SHALL een duidelijke foutmelding tonen met de reden van de fout
4. WHEN een cluster succesvol is verbonden, THE Kubernetes_Cluster SHALL de beschikbare namespaces ophalen en tonen
5. THE Kubernetes_Cluster SHALL ondersteuning bieden voor standaard Kubernetes, k3s, OKD en OpenShift clusters
6. WHEN een gebruiker een cluster verwijdert, THE Kubernetes_Cluster SHALL eerst controleren of er actieve deployments zijn en een waarschuwing tonen

### Requirement 2: Kubernetes Destination Configuratie

**User Story:** Als een Coolify gebruiker wil ik Kubernetes namespaces als deployment destinations kunnen configureren, zodat ik applicaties naar specifieke namespaces kan deployen.

#### Acceptance Criteria

1. WHEN een gebruiker een nieuwe Kubernetes destination aanmaakt, THE Kubernetes_Destination SHALL een namespace selectie en configuratie interface tonen
2. THE Kubernetes_Destination SHALL een polymorphic relatie hebben met Application, Service en database modellen (vergelijkbaar met StandaloneDocker)
3. WHEN een namespace niet bestaat, THE Kubernetes_Destination SHALL de optie bieden om deze automatisch aan te maken
4. THE Kubernetes_Destination SHALL resource limits (CPU, memory) kunnen configureren als defaults voor deployments
5. WHEN een destination wordt verwijderd, THE Kubernetes_Destination SHALL controleren of er resources aan gekoppeld zijn

### Requirement 3: Kubernetes Manifest Generatie

**User Story:** Als een Coolify gebruiker wil ik dat mijn applicatie configuraties automatisch worden omgezet naar Kubernetes manifests, zodat ik geen handmatige YAML hoef te schrijven.

#### Acceptance Criteria

1. WHEN een applicatie wordt gedeployed naar Kubernetes, THE Manifest_Generator SHALL een Deployment manifest genereren met de juiste container specificaties
2. WHEN een applicatie poorten exposed, THE Manifest_Generator SHALL een Service manifest genereren met de juiste port mappings
3. WHEN een applicatie een FQDN heeft, THE Manifest_Generator SHALL een Ingress manifest genereren voor HTTP/HTTPS routing
4. WHEN een applicatie environment variables heeft, THE Manifest_Generator SHALL een ConfigMap en/of Secret manifest genereren
5. WHEN een applicatie persistent volumes nodig heeft, THE Manifest_Generator SHALL PersistentVolumeClaim manifests genereren
6. THE Manifest_Generator SHALL de gegenereerde manifests als YAML kunnen exporteren voor review
7. FOR ALL gegenereerde manifests, parsing dan formatting dan parsing SHALL een equivalent manifest produceren (round-trip property)

### Requirement 4: Kubernetes Deployment Job

**User Story:** Als een Coolify gebruiker wil ik applicaties kunnen deployen naar Kubernetes met dezelfde workflow als Docker deployments, zodat de ervaring consistent is.

#### Acceptance Criteria

1. WHEN een deployment wordt gestart naar een Kubernetes destination, THE KubernetesDeploymentJob SHALL de manifests genereren en toepassen op het cluster
2. WHEN een deployment loopt, THE KubernetesDeploymentJob SHALL de deployment status monitoren en logs streamen
3. IF een deployment faalt, THEN THE KubernetesDeploymentJob SHALL automatisch een rollback uitvoeren naar de vorige versie
4. WHEN een deployment succesvol is, THE KubernetesDeploymentJob SHALL wachten tot alle pods ready zijn voordat de status op "running" wordt gezet
5. THE KubernetesDeploymentJob SHALL health checks uitvoeren op basis van de applicatie health check configuratie
6. WHEN een gebruiker een rollback aanvraagt, THE KubernetesDeploymentJob SHALL de vorige deployment versie herstellen

### Requirement 5: Scaling en Autoscaling

**User Story:** Als een Coolify gebruiker wil ik mijn Kubernetes deployments kunnen schalen en autoscaling kunnen configureren, zodat mijn applicaties automatisch kunnen meeschalen met de load.

#### Acceptance Criteria

1. WHEN een gebruiker het aantal replicas wijzigt, THE Kubernetes_Destination SHALL de Deployment updaten met het nieuwe replica count
2. WHEN een gebruiker autoscaling inschakelt, THE Manifest_Generator SHALL een HorizontalPodAutoscaler manifest genereren
3. THE HPA configuratie SHALL minimum replicas, maximum replicas en target CPU/memory utilization ondersteunen
4. WHEN autoscaling actief is, THE UI SHALL de huidige en gewenste replica counts tonen
5. IF de cluster geen metrics-server heeft, THEN THE Kubernetes_Destination SHALL een waarschuwing tonen dat autoscaling niet beschikbaar is

### Requirement 6: UI Uitbreiding voor Kubernetes

**User Story:** Als een Coolify gebruiker wil ik een duidelijke keuze hebben tussen Docker en Kubernetes deployment targets, zodat ik de juiste deployment methode kan kiezen.

#### Acceptance Criteria

1. WHEN een gebruiker een nieuwe resource aanmaakt, THE UI SHALL een keuze tonen tussen "Deploy naar Docker" en "Deploy naar Kubernetes"
2. WHEN Kubernetes is geselecteerd, THE UI SHALL alleen Kubernetes destinations tonen als opties
3. THE Server configuratie pagina SHALL een sectie hebben voor Kubernetes cluster beheer
4. WHEN een Kubernetes deployment actief is, THE UI SHALL pod status, logs en events kunnen tonen
5. THE UI SHALL Kubernetes-specifieke configuratie opties tonen (replicas, resource limits, autoscaling)
6. WHEN een gebruiker pod logs bekijkt, THE UI SHALL real-time log streaming ondersteunen

### Requirement 7: Docker Compose naar Kubernetes Conversie

**User Story:** Als een Coolify gebruiker wil ik mijn bestaande Docker Compose configuraties kunnen deployen naar Kubernetes, zodat ik makkelijk kan migreren.

#### Acceptance Criteria

1. WHEN een Docker Compose applicatie wordt gedeployed naar Kubernetes, THE Manifest_Generator SHALL de compose services omzetten naar Kubernetes Deployments
2. THE conversie SHALL Docker Compose volumes omzetten naar PersistentVolumeClaims
3. THE conversie SHALL Docker Compose networks omzetten naar Kubernetes Services
4. THE conversie SHALL Docker Compose environment variables omzetten naar ConfigMaps/Secrets
5. IF een Docker Compose feature niet ondersteund wordt in Kubernetes, THEN THE Manifest_Generator SHALL een waarschuwing loggen

### Requirement 8: Git-based en Image Deployments

**User Story:** Als een Coolify gebruiker wil ik zowel git-based als Docker image deployments naar Kubernetes kunnen doen, zodat alle deployment methodes ondersteund worden.

#### Acceptance Criteria

1. WHEN een git-based applicatie wordt gedeployed naar Kubernetes, THE KubernetesDeploymentJob SHALL eerst de image bouwen en naar een registry pushen
2. THE Kubernetes deployment SHALL de image pullen van de registry (niet lokaal bouwen in het cluster)
3. WHEN een Docker image deployment wordt geconfigureerd, THE KubernetesDeploymentJob SHALL de image direct gebruiken in de Deployment manifest
4. THE deployment SHALL image pull secrets ondersteunen voor private registries
5. WHEN Nixpacks wordt gebruikt, THE build process SHALL identiek zijn aan Docker deployments, alleen de deployment target verschilt

### Requirement 9: Cluster Authenticatie en Beveiliging

**User Story:** Als een Coolify beheerder wil ik veilige authenticatie naar Kubernetes clusters, zodat credentials beschermd zijn.

#### Acceptance Criteria

1. THE Kubernetes_Cluster SHALL kubeconfig credentials encrypted opslaan in de database
2. THE Kubernetes_Cluster SHALL ondersteuning bieden voor service account tokens, client certificates en OIDC authenticatie
3. WHEN een kubeconfig wordt geüpload, THE Kubernetes_Cluster SHALL gevoelige data (tokens, certificates) extraheren en apart encrypted opslaan
4. THE Kubernetes_Cluster SHALL RBAC permissions valideren bij het verbinden (minimaal: get/list/create/update/delete voor pods, deployments, services, ingress)
5. IF RBAC permissions onvoldoende zijn, THEN THE Kubernetes_Cluster SHALL specifiek aangeven welke permissions ontbreken

### Requirement 10: Multi-Cluster Ondersteuning

**User Story:** Als een Coolify gebruiker wil ik meerdere Kubernetes clusters kunnen beheren, zodat ik applicaties naar verschillende omgevingen kan deployen.

#### Acceptance Criteria

1. THE Kubernetes_Cluster model SHALL meerdere clusters per team ondersteunen
2. WHEN een gebruiker een applicatie configureert, THE UI SHALL alle beschikbare clusters en namespaces tonen
3. THE deployment history SHALL bijhouden naar welk cluster en namespace elke deployment ging
4. WHEN een cluster onbereikbaar wordt, THE monitoring SHALL een notificatie sturen naar het team
